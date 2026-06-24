<?php
/**
 * Prints a current HelpScout OAuth access token to the console.
 *
 * Useful for troubleshooting — e.g. testing an API query with cURL, where you
 * need a valid bearer token. Reuses getAccessToken() and the .env bootstrap
 * from hsexports.php (which no longer runs the exporter when required).
 */
require __DIR__ . '/hsexports.php';

echo "Access Token: " . getAccessToken() . "\n";
