# Novel Scraper Collection

A collection of dependency-free PHP CLI scrapers that download web novels and
compile them into beautifully formatted, A5-optimized HTML files ready for
printing or offline reading.

Three scrapers are included, each targeting a different website:

| Scraper | Target site | Script |
|---|---|---|
| **NovelBin** | `novelbin.com` / `novelbin.org` | `novelbin/novelbin.php` |
| **FanMTL** | `fanmtl.com` | `fanmtl/fanmtl.php` |
| **NovelHall** | `novelhall.com` | `novelhall/novelhall.php` |

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick Start](#quick-start)
5. [Common Options](#common-options)
6. [Scraper Reference](#scraper-reference)
   - [NovelBin](#novelbin)
   - [FanMTL](#fanmtl)
   - [NovelHall](#novelhall)
7. [Output Structure](#output-structure)
8. [How It Works](#how-it-works)
9. [Troubleshooting](#troubleshooting)
10. [Running the Tests](#running-the-tests)
11. [Contributing](#contributing)
12. [License](#license)

---

## Features

- **Zero dependencies** — standard PHP only; no Composer, no external libraries.
- **A5-optimized HTML output** — embedded CSS styled for A5 paper with a
  classic serif font (Libre Baskerville).
- **Flexible chapter grouping** — split any novel into parts of any size
  (default: 100 chapters per file).
- **Intelligent content extraction** — heuristic scoring picks the best
  content block; removes ads, navigation, comments, and boilerplate.
- **Multiple TOC strategies** — AJAX endpoint, multi-page pagination, static
  link scraping, and embedded-chapter detection.
- **Sequential renumbering** — chapters are renumbered `Chapter N: Title` by
  default; use `--preserve-numbers` to keep original site titles.
- **Auto-order correction** — if the chapter list appears to be in descending
  order it is automatically reversed.
- **Termux / Android friendly** — `--download` saves directly to
  `~/storage/shared/Download`.
- **Configurable throttle** — minimum enforced per-scraper; respects server
  rate limits.

---

## Requirements

- PHP **8.0** or newer (PHP 8.1+ recommended)
- PHP extensions: `curl`, `xml` / `dom`, `mbstring`

### Installing PHP on Termux (Android)

```bash
pkg update && pkg install php
```

### Installing PHP on Debian / Ubuntu

```bash
sudo apt install php php-curl php-xml php-mbstring
```

### Installing PHP on macOS (Homebrew)

```bash
brew install php
```

---

## Installation

Clone or download the repository:

```bash
git clone https://github.com/druvx13/novelbin-scraper.git
cd novelbin-scraper
```

No build step is needed. Each scraper is a single self-contained PHP file.

---

## Quick Start

```bash
# NovelBin
php novelbin/novelbin.php --url "https://novelbin.com/b/super-gene" --start 1 --end 10

# FanMTL
php fanmtl/fanmtl.php --url "https://www.fanmtl.com/novel/6954524.html" --start 1 --end 5

# NovelHall
php novelhall/novelhall.php --url "https://www.novelhall.com/a-novel-name-12345/" --start 1 --end 5
```

Run without `--url` to enter **interactive mode** (step-by-step prompts):

```bash
php novelbin/novelbin.php
```

---

## Common Options

All three scrapers share the same core set of options:

| Option | Description | Default |
|---|---|---|
| `--url <URL>` | Novel main page URL **(required)** | — |
| `--out <name>` | Output folder / filename base | novel title |
| `--start <N>` | First chapter to download (1-based) | `1` |
| `--end <N>` | Last chapter to download (1-based) | last |
| `--throttle <sec>` | Seconds between requests | scraper default |
| `--download` | Save to `~/storage/shared/Download` (Termux) | off |
| `--group-size <N>` | Chapters per output file | `100` |
| `--preserve-numbers` | Keep original site chapter titles | off |
| `--help` | Show help and exit | — |

---

## Scraper Reference

### NovelBin

**Script:** `novelbin/novelbin.php`  
**Supported domains:** `novelbin.com`, `novelbin.org`, `thenovelbin.org`, `novlove.com`  
**Default throttle:** 1.0 s

#### How chapter lists are fetched

1. Looks for a `data-novel-id` attribute and requests
   `/ajax/chapter-archive?novelId=<id>` (primary AJAX strategy).
2. Falls back to scanning `div.list-chapter a` and similar selectors.

#### Example

```bash
php novelbin/novelbin.php \
  --url "https://novelbin.com/b/super-gene" \
  --start 1 --end 100 \
  --throttle 1.5 \
  --download
```

Full reference: [`novelbin/README.md`](novelbin/README.md)

---

### FanMTL

**Script:** `fanmtl/fanmtl.php`  
**Supported domains:** `fanmtl.com`, `www.fanmtl.com`  
**Default throttle:** 3.0 s (minimum enforced)

#### How chapter lists are fetched

FanMTL uses a Readwn-style multi-page TOC. The scraper:

1. Detects `?page=N` or `/page/N/` pagination links.
2. Iterates over every TOC page, collecting `ul.chapter-list a` entries.
3. Falls back to `rel="next"` link following, then generic link scanning.

#### Example

```bash
php fanmtl/fanmtl.php \
  --url "https://www.fanmtl.com/novel/6954524.html" \
  --start 51 \
  --preserve-numbers
```

Full reference: [`fanmtl/README.md`](fanmtl/README.md)

---

### NovelHall

**Script:** `novelhall/novelhall.php`  
**Supported domains:** `novelhall.com`, `www.novelhall.com`  
**Default throttle:** 1.0 s

#### How chapter lists are fetched

All `div.book-catalog` blocks are collected; the one containing the most
`<a>` elements is used as the chapter list (mirrors WebToEpub's
`NovelhallParser.getChapterUrls` logic).

#### Metadata selectors

| Field | Selector |
|---|---|
| Title | `div.book-info h1` |
| Author | `<meta property="books:author">` content |
| Summary | `div.book-info div.intro` |
| Cover | `div.book-img img` |
| Chapter content | `article div.entry-content` |
| Chapter title | `article div.single-header h1` |

#### Example

```bash
php novelhall/novelhall.php \
  --url "https://www.novelhall.com/a-novel-name-12345/" \
  --start 1 --end 50
```

Full reference: [`novelhall/README.md`](novelhall/README.md)

---

## Output Structure

Each scraper creates a folder (named after the novel or `--out`) and fills it
with part files:

```
Super Gene/
├── Super Gene(1-100).html
├── Super Gene(101-200).html
└── Super Gene(201-250).html
```

- Folder location: current working directory, or
  `~/storage/shared/Download` when `--download` is set.
- File naming: `{OutputName}({startChapter}-{endChapter}).html`
- Each HTML file is self-contained (inlined CSS, no external assets).

---

## How It Works

Every scraper follows the same pipeline:

```
1. Fetch novel page
        │
        ▼
2. Extract metadata (title, author, summary, cover)
        │
        ▼
3. Build chapter list  ──────────────────────────────────────────────
   │  Strategy A: AJAX endpoint (NovelBin)                          │
   │  Strategy B: Paginated TOC (FanMTL)                            │
   │  Strategy C: Largest div.book-catalog block (NovelHall)        │
   └──> fallback: generic link scan                                  │
        │                                                            │
        ▼                                                            │
4. Slice to --start / --end range                                    │
        │                                                            │
        ▼                                                            │
5. Fetch each chapter page (throttled)                               │
   └──> score candidate content blocks by text length + ¶ count     │
   └──> extract chapter title                                        │
   └──> strip scripts, ads, nav, comments                           │
        │                                                            │
        ▼                                                            │
6. Optionally renumber chapters (Chapter N: Short Title)            │
        │                                                            │
        ▼                                                            │
7. Split into groups (--group-size)                                  │
        │                                                            │
        ▼                                                            │
8. Write A5 HTML file(s) to disk                                     │
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `No chapters found` | Site layout changed or wrong URL | Check the URL; see scraper README for selectors |
| `HTTP 403 / 429` | Rate limiting or bot detection | Increase `--throttle`; try a different IP |
| `HTTP 404` | Novel has moved or been deleted | Verify the URL in a browser |
| Garbled/missing text | `mbstring` not enabled | `php -m | grep mbstring`; enable in `php.ini` |
| Very slow download | Throttle is too high | Lower `--throttle` (keep it ≥ 1.0 to be safe) |
| Empty chapter content | Selector mismatch | File a bug with the novel URL |
| `curl_close()` warning | PHP ≥ 8.5 | Already removed in all scrapers — upgrade to latest version |

---

## Running the Tests

A unit test suite covers shared utility functions (URL joining, filename
sanitizing, DOM helpers, content cleaning):

```bash
php tests.php
```

Expected output:

```
Results: 20 passed / 20 total
```

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for instructions on adding new scrapers,
coding conventions, and the test layout.

---

## License

This project is licensed under the terms of the FFP license.  
See [LICENSE.md](LICENSE.md) for the full text.
