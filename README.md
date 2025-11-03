# NovelBin Scraper

A powerful, dependency-free PHP script designed to scrape novels from NovelBin-family websites and compile them into beautifully formatted, A5-optimized HTML files ready for printing or digital archiving.

## Introduction

This script provides a simple and effective way to download and archive your favorite web novels for offline reading. It intelligently parses novel pages, extracts chapter content, and assembles everything into clean, readable HTML documents. The output is specifically styled for the A5 paper size, making it ideal for printing and binding into physical books.

## Features

- **Zero Dependencies**: Runs on standard PHP without needing Composer or any external libraries.
- **A5-Optimized Output**: Generates HTML with CSS specifically designed for A5 paper, featuring a classic book-like font (Libre Baskerville) and layout.
- **Flexible Chapter Grouping**: Combine chapters into parts of any size (e.g., 100 chapters per file), making large novels manageable.
- **Intelligent Content Extraction**: Employs multiple strategies to find and clean chapter content, removing ads, navigation menus, and other non-essential elements.
- **AJAX and Static Scraping**: Capable of fetching chapter lists from both modern AJAX-based loaders and traditional static HTML links.
- **Termux Compatibility**: Includes a `--download` flag for easy saving to the shared `Download` folder on Android devices via Termux.
- **Resilient and Robust**: Handles relative URLs, sanitizes filenames, and includes configurable throttling to avoid overwhelming the server.

## Requirements

- PHP 7.4 or newer
- `php-curl` extension (for HTTP requests)
- `php-xml` extension (for DOM parsing)
- `php-mbstring` extension (for multi-byte string operations)

## Installation

1.  Ensure you have PHP and the required extensions installed on your system.
2.  Download the `index.php` script to a directory of your choice.
3.  Make sure the script is executable (optional but recommended for CLI usage):
    ```bash
    chmod +x index.php
    ```

## Usage

The script can be run either interactively or directly via command-line arguments.

### Interactive Mode

For a guided experience, run the script without any arguments:

```bash
php index.php
```

The script will prompt you to enter the novel URL, the desired output name, chapter range, and other settings.

### Command-Line Arguments

For automation and advanced usage, provide the options as command-line flags.

```bash
php index.php --url "<URL>" [--out "Name"] [--start N] [--end N] [--throttle 1.0] [--download] [--group-size 100] [--help]
```

#### Options:

-   `--url`: **(Required)** The URL of the novel's main page.
-   `--out`: The base name for the output folder and HTML files. If omitted, the script uses the novel's title.
-   `--start`: The first chapter to download (1-based index). Defaults to the very first chapter.
-   `--end`: The last chapter to download (1-based index). Defaults to the very last chapter.
-   `--throttle`: The delay in seconds between consecutive HTTP requests to avoid rate-limiting. Default is `1.0`.
-   `--download`: A flag that, when present, instructs the script to save files in `~/storage/shared/Download` (primarily for Termux users).
-   `--group-size`: The number of chapters to include in each generated HTML file. Default is `100`.
-   `--help`: Displays the help message and exits.

### Usage Examples

**1. Basic Download**
Download all chapters of a novel and let the script determine the name.
```bash
php index.php --url "https://novelbin.org/novel/the-legend-of-the-arch-magus"
```

**2. Specific Chapter Range and Grouping**
Download chapters 51 to 250, grouping them into files of 100 chapters each.
```bash
php index.php --url "https://novelbin.org/novel/super-gene" --start 51 --end 250 --group-size 100
```
*Output*: Two files, `Super-Gene(51-150).html` and `Super-Gene(151-250).html`.

**3. Custom Output Name and Slower Pace**
Download the first 50 chapters with a custom name and a 2-second delay between requests.
```bash
php index.php --url "https://novelbin.org/novel/versatile-mage" --out "VMage" --end 50 --throttle 2.0
```
*Output*: A folder named `VMage` containing `VMage(1-50).html`.

## How It Works

The script follows a multi-step process to scrape and assemble the novel:

1.  **Parse Main Page**: It first fetches the main novel URL provided via the `--url` argument.
2.  **Extract Metadata**: It parses the page to find the novel's title, author, summary, and cover image URL.
3.  **Find Chapter List**: The script attempts two methods to get the chapter list:
    *   **AJAX Request (Primary)**: It looks for a `novelId` in the HTML and uses it to make an AJAX request to a chapter archive endpoint. This is the most reliable method for modern NovelBin sites.
    *   **Static Scraping (Fallback)**: If the AJAX method fails, it scans the page for all links that appear to be chapters and compiles a list from them.
4.  **Fetch Chapter Content**: It iterates through the desired range of chapters. For each chapter, it sends an HTTP request to its URL.
5.  **Clean and Extract**: The downloaded chapter HTML is aggressively cleaned. The script uses a series of XPath queries to identify the main content block while removing navigation, sidebars, comment sections, and other boilerplate. The chapter title is also extracted.
6.  **Build HTML Parts**: The cleaned chapters are grouped according to the `--group-size`.
7.  **Generate Final Document**: For each group, it generates a complete HTML file, embedding the A5-optimized CSS, the novel's metadata (title, author, summary), and the chapter content. The final file is saved to disk.

## Output Structure

The script creates a main folder in the current directory (or the `Download` folder if `--download` is used). The folder's name is either the sanitized novel title or the name specified with `--out`.

Inside this folder, the HTML files are generated. The naming convention is:
`{OutputName}({StartChapter}-{EndChapter}).html`

For example, with `--out "My-Novel"` and `--group-size 50`, the output would look like this:
```
My-Novel/
├── My-Novel(1-50).html
├── My-Novel(51-100).html
└── My-Novel(101-150).html
```

## Troubleshooting

-   **"No chapters found"**: This can happen if the website has changed its layout significantly. Ensure the domain is on the supported list. If it is, the script's XPath selectors may need updating.
-   **"HTTP 403 Forbidden"**: The website may be blocking the script's User-Agent or IP. Try increasing the `--throttle` value to be more respectful of the server's rate limits.
-   **"Maximum execution time exceeded"**: For very large novels, the script might time out. You can run it from the command line with a higher time limit: `php -d max_execution_time=0 index.php ...`.
-   **Garbled Text**: Ensure your system's `php.ini` has `mbstring` enabled and properly configured for UTF-8.

## Supported Sites

The script is primarily tested and maintained for the following domains. It may work on other mirrors with similar HTML structures.

-   `novelbin.org`
-   `thenovelbin.org`
-   `novelbin.com`
-   `novlove.com`

## License

This project is licensed under the terms of the MIT license. See the [LICENSE](LICENSE) file for more details.
