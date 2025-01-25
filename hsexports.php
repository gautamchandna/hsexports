<?php
ini_set('memory_limit', '512M');

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Check if .env file exists, and if not, run setup script
if (!file_exists(__DIR__ . '/.env')) {
    echo "The .env file is missing. Running setup to generate it...\n";
    setupEnv();
}

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if (!isset($_ENV['HELPSCOUT_CLIENT_ID']) || !isset($_ENV['HELPSCOUT_CLIENT_SECRET'])) {
    exit("You must define environment variables to connect to HelpScout\n");
}

// Function to set up the .env file interactively
function setupEnv() {
    echo "Setting up your .env file...\n";
    $clientId = readline("Enter your HELPSCOUT_CLIENT_ID: ");
    $clientSecret = readline("Enter your HELPSCOUT_CLIENT_SECRET: ");

    $envContent = "HELPSCOUT_CLIENT_ID={$clientId}\nHELPSCOUT_CLIENT_SECRET={$clientSecret}\n";
    file_put_contents(__DIR__ . '/.env', $envContent);

    echo ".env file created successfully! Please rerun the script.\n";
    exit();
}

function makeApiGetRequest($client, $url, $queryParams = []) {
    while (true) {
        try {
            $response = $client->get($url, ['query' => $queryParams]);

            // Extract rate limit headers
            $remaining = $response->getHeader('X-RateLimit-Remaining-Minute')[0] ?? 1;
            $retryAfter = $response->getHeader('X-RateLimit-Retry-After')[0] ?? null;

            if ($retryAfter) {
                echo "Rate limit reached. Waiting for $retryAfter seconds...\n";
                sleep((int)$retryAfter);
                continue; // Retry after sleep
            }

            if ((int)$remaining <= 1) { // If only 1 requests are left, pause
                echo "Approaching rate limit. Pausing for 1 min for safety...\n";
                sleep(60); // Wait 1 minute before continuing
            }

            return $response;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 429) {
                $retryAfter = $e->getResponse()->getHeader('X-RateLimit-Retry-After')[0] ?? 60;
                echo "Rate limit exceeded! Waiting for $retryAfter seconds...\n";
                sleep((int)$retryAfter);
                continue; // Retry the request
            }
            throw $e; // Other API errors should not be ignored
        }
    }
}

// Function to get OAuth Access Token from HelpScout
function getAccessToken() {
    $client = new Client();
    $response = $client->post('https://api.helpscout.net/v2/oauth2/token', [
        'form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => $_ENV['HELPSCOUT_CLIENT_ID'],
            'client_secret' => $_ENV['HELPSCOUT_CLIENT_SECRET'],
        ],
    ]);

    $data = json_decode($response->getBody(), true);
    return $data['access_token'];
}

// Function to parse natural date ranges like "May 2024" or "May - June 2024"
function parseDateRange($dateInput) {
    $dateInput = strtolower(trim($dateInput));

    // Match single month-year pattern (e.g., "May 2024")
    if (preg_match('/^(\w+)\s+(\d{4})$/', $dateInput, $matches)) {
        $startDate = date("Y-m-d", strtotime("first day of {$matches[1]} {$matches[2]}"));
        $endDate = date("Y-m-d", strtotime("last day of {$matches[1]} {$matches[2]}"));
        return [$startDate, $endDate];
    }

    // Match range of months (e.g., "May - June 2024")
    if (preg_match('/^(\w+)\s*-\s*(\w+)\s+(\d{4})$/', $dateInput, $matches)) {
        $startDate = date("Y-m-d", strtotime("first day of {$matches[1]} {$matches[3]}"));
        $endDate = date("Y-m-d", strtotime("last day of {$matches[2]} {$matches[3]}"));
        return [$startDate, $endDate];
    }

    throw new Exception("Invalid date format. Please use 'Month Year' or 'Month - Month Year'.");
}

// Function to sanitize HTML before processing
function preSanitizeHtml($html) {
    // Remove unwanted special characters
    $html = preg_replace('/[\x00-\x1F\x7F]/u', '', $html);

    // Strip high-level ASCII characters (non-UTF8-safe)
    $html = preg_replace('/[\x80-\xFF]/u', '', $html);

    // Basic stripping of unnecessary whitespace
    $html = trim($html);

    return $html;
}

function sanitizeFileOrFoldername($name) {

    $name = preg_replace('/[^a-zA-Z0-9_ -]/', '', $name);
    
    // Remove problematic characters
    $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
    
    // Replace multiple spaces/tabs with a single space
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Trim leading and trailing spaces
    $name = trim($name);

    // If the name is empty after cleaning, use a placeholder
    return $name !== '' ? $name : '-';
}

// Windows cannot handle folder and file names with : in them
function formatTimestamp($timestamp) {
    return date('Y-m-d-H-i-s', strtotime($timestamp));
}

// Function to fetch mailboxes and prompt for selection
function selectMailbox($accessToken) {
    $client = new Client([
        'base_uri' => 'https://api.helpscout.net/v2/',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ],
    ]);

    $response = $client->get('mailboxes');
    $data = json_decode($response->getBody(), true);

    if (!isset($data['_embedded']['mailboxes'])) {
        throw new Exception("No mailboxes found.");
    }

    $mailboxes = $data['_embedded']['mailboxes'];

    // Add "All Mailboxes" as the first option
    echo "1. All Mailboxes\n";
    foreach ($mailboxes as $index => $mailbox) {
        echo ($index + 2) . ". " . $mailbox['name'] . " (ID: " . $mailbox['id'] . ")\n";
    }

    echo "Select a mailbox by number: ";
    $selection = trim(fgets(STDIN));

    if (!is_numeric($selection) || $selection < 1 || $selection > (count($mailboxes) + 1)) {
        throw new Exception("Invalid selection.");
    }

    if ($selection == 1) {
        return 'all'; // Special value for all mailboxes
    }

    return $mailboxes[$selection - 2];
}

// Function to fetch tags
function fetchTags($conversation) {
    if (!isset($conversation['tags'])) {
        return '';
    }

    $tags = [];
    foreach ($conversation['tags'] as $tag) {
        $tags[] = $tag['tag'];
    }

    return implode(', ', $tags);
}

// Function to fetch threads for a given conversation
function fetchThreads($conversationId, $accessToken) {
    echo "Fetching threads...\n";

    $client = new Client([
        'base_uri' => 'https://api.helpscout.net/v2/',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ],
    ]);

    $response = makeApiGetRequest($client, "conversations/{$conversationId}/threads");
    return json_decode($response->getBody(), true)['_embedded']['threads'] ?? [];
}

// Function to fetch conversations from HelpScout within the specified date range and stream to CSV
function fetchAndStreamConversations($startDate, $endDate, $accessToken, $filename, $selectedMailboxId) {
    echo "\n\nFetching conversations...\n";

    $client = new Client([
        'base_uri' => 'https://api.helpscout.net/v2/',
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ],
    ]);

    $page = 1;
    $exportPath = __DIR__ . '/conversations';
    if (!file_exists($exportPath)) {
        mkdir($exportPath, 0777, true);
    }

    // Open the file for writing
    $fp = fopen($filename, 'w');
    $headerWritten = false;
    $total_pages = null;
    $total_elements = null;

    do {
        if ($total_pages === null) {
            echo "Processing the first page...\n";
        } else {
            echo "Processing page $page of $total_pages, containing $total_elements...\n";
        }
        
        $query = [
            'query' => "(createdAt:[{$startDate}T00:00:00Z TO {$endDate}T23:59:59Z])",
            'status' => 'all',
            'page' => $page,
            'sortField' => 'createdAt',
            'embed' => 'threads',
            'sortOrder' => 'desc',
        ];

        if ($selectedMailboxId !== 'all') {
            $query['mailbox'] = $selectedMailboxId['id'];
        }

        $response = makeApiGetRequest($client, 'conversations', $query);
        
        $data = json_decode($response->getBody(), true);
        
        if (isset($data['_embedded']['conversations'])) {
            foreach ($data['_embedded']['conversations'] as $conversation) {
                // Exclude conversations tagged as "spam" or "discard"
                $tags = [];
                if (isset($conversation['tags']) && is_array($conversation['tags'])) {
                    $tags = array_map(function ($tag) {
                        return is_string($tag) ? strtolower($tag) : '';
                    }, $conversation['tags']);
                }
                if (in_array('spam', $tags) || in_array('discard', $tags)) {
                    continue;
                }

                $customerName = trim(($conversation['primaryCustomer']['first'] ?? '') . ' ' . ($conversation['primaryCustomer']['last'] ?? ''));
                $customerName = sanitizeFileOrFoldername($customerName);
                $createdAt = formatTimestamp($conversation['_embedded']['threads'][0]['createdAt'] ?? $conversation['createdAt'] ?? '');
                $conversationId = $conversation['id'];

                // Create folder for mailbox
                $mailboxFolder = "{$exportPath}/{$conversation['mailboxId']}";
                if (!file_exists($mailboxFolder)) {
                    mkdir($mailboxFolder, 0777, true);
                }

                // Create folder for conversation
                $conversationFolder = "{$mailboxFolder}/{$createdAt}_{$conversationId}_{$customerName}";
                if (!file_exists($conversationFolder)) {
                    mkdir($conversationFolder, 0777, true);
                }

                // Fetch and export threads
                $threads = fetchThreads($conversationId, $accessToken);
                foreach ($threads as $thread) {
                    $creator = trim(($thread['createdBy']['first'] ?? '') . ' ' . ($thread['createdBy']['last'] ?? ''));
                    $creator = sanitizeFileOrFoldername($creator); // Sanitize creator name
                    $threadId = $thread['id'];
                    $threadCreatedAt = formatTimestamp($thread['createdAt'] ?? '');

                    $threadFileName = "{$conversationFolder}/thread_{$threadCreatedAt}_{$threadId}_{$creator}.json";
                    file_put_contents($threadFileName, json_encode($thread, JSON_PRETTY_PRINT));
                }

                // Prepare row for CSV export
                $ticket = [
                    'id' => $conversationId,
                    'mailbox' => $conversation['mailboxId'],
                    'status' => $conversation['status'],
                    'name' => $customerName,
                    'email' => $conversation['primaryCustomer']['email'] ?? '',
                    'ticket' => $conversation['_links']['web']['href'] ?? '',
                    'tags' => fetchTags($conversation),
                    'date_received' => $createdAt,
                    'initial_message' => $conversation['_embedded']['threads'][0]['body'] ?? '',
                    'threads_count' => count($threads),
                ];

                if (!$headerWritten) {
                    fputcsv($fp, array_keys($ticket));
                    $headerWritten = true;
                }

                fputcsv($fp, $ticket);
            }
        }

        $page++;
        $total_pages = $data['page']['totalPages'];
        $total_elements = $data['page']['totalElements'];
    } while ($data['page']['totalPages'] >= $page);

    fclose($fp);
    echo "Conversations and threads export completed!\n\n";
}

function main() {
    global $argv;
    if (count($argv) !== 2) {
        echo "Usage: php script.php \"Month Year\" or \"Month - Month Year\"\n";
        exit(1);
    }

    [$startDate, $endDate] = parseDateRange($argv[1]);
    $accessToken = getAccessToken();

    // Prompt user to select a mailbox
    $selectedMailbox = selectMailbox($accessToken);

    $filename = __DIR__ . '/export-' . $startDate . '-to-' . $endDate . '-' . ($selectedMailbox === 'all' ? 'all-mailboxes' : str_replace(' ', '-', strtolower($selectedMailbox['name']))) . '.csv';
    fetchAndStreamConversations($startDate, $endDate, $accessToken, $filename, $selectedMailbox);
}

main();
