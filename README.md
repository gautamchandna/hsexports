# **HSExports -- Exporting Helpscout Conversations with PHP CLI**
_This PHP CLI script helps you export HelpScout Conversations, with by date range and mailbox with the initial message included._

[![License](https://img.shields.io/badge/license-CC0_1.0-lightgrey.svg)](LICENSE) [![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-green)](https://www.php.net/)

---

## **Features**  
- Quick setup  
- Terminal instructions and help  
- Highly Customizable  

---

## **Installation**  

1. Clone the repository:  
   git clone https://github.com/mathetos/hsexports
2. Navigate to the directory:
   cd hsexports
3. Install Composer:
   composer install
4. Generate your .env file:
    You need to save your Helpscout APP ID and Secret Key in an .env file
    You can do that manually with the provided .env.sample file, or just run the script for the first time and it will ask you to provide those keys and it will generate the .env file for you.

Now you're ready to run the script.

## **Usage**
Run the script using the PHP CLI:

`php hsexports.php "<date-range>" [mailbox]`

**Date range** accepts natural formats:
- `"May 2024"` — a single month
- `"May - June 2024"` — a range of months within one year
- `"2024"` — a full year
- `"2024 - 2026"` — a range of years (fetched and saved one year per CSV)

**Mailbox** (optional) — pass a mailbox name (full or partial, e.g. `"POP Support"`), a numeric mailbox ID, or `all`. Omit it and the script lists your mailboxes (with "All Mailboxes" as option 1) for you to pick interactively.

Examples:

```
php hsexports.php "May 2024"                 # interactive mailbox picker
php hsexports.php "2025" "POP Support"        # one mailbox, non-interactive
php hsexports.php "2024 - 2026" all           # every mailbox, three years
```

Conversations and their threads are written under `conversations/{mailboxId}/`, and a summary CSV is written per year as `export-{start date}-to-{end date}-{mailbox}.csv`.

## **Tips**
1. The Helpscout API is slow and because we're including the initial message in the export, it can be a serious memory hog. So I recommend exporting one month at a time, or even one week at a time depending on the size of your inbox. 
2. I've included a file called `accesstoken.php` for troubleshooting purposes. If you want to test a query against the Helpscout API using cURL, knowing your current access token is crucial. Simply run the file and you'll get your access token in the Terminal. 

## **Contributing**
Contributions are welcome! Please fork this repository, create a new branch, and submit a pull request.

## **License**
This project is released under the Creative Commons Zero v1.0 Universal (CC0 1.0) license. You can copy, modify, and distribute this work, even for commercial purposes, without asking permission.

## Acknowledgments
This project wouldn't have been possible without [@zbtirrell](https://github.com/zbtirrell). When I had this problem, he solved it with the beginnings of this script. I've since heavily enhanced and improved it, but at the time I didn't even understand how powerful and helpful PHP CLI was. Thanks Zach!







