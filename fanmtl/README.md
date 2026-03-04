# FanMTL Scraper

Single-file PHP scraper for **FanMTL** (Readwn-style) sites.  
Script: `fanmtl/fanmtl.php`

---

## Supported Domains

| Domain | Notes |
|---|---|
| `fanmtl.com` | primary |
| `www.fanmtl.com` | www prefix |

---

## Usage

### One-liner

```bash
php fanmtl/fanmtl.php --url "<URL>" [OPTIONS]
```

### Interactive mode

```bash
php fanmtl/fanmtl.php
```

Prompts for URL, throttle, output name, chapter range, download flag, group
size, and whether to preserve original chapter numbering.

---

## Options

| Option | Type | Default | Description |
|---|---|---|---|
| `--url <URL>` | string | — | Novel main page URL **(required)** |
| `--out <name>` | string | novel title | Output folder / filename base |
| `--start <N>` | int | `1` | First chapter to download (1-based) |
| `--end <N>` | int | last | Last chapter to download (1-based) |
| `--throttle <sec>` | float | `3.0` | Seconds to wait between requests (minimum 3.0 enforced) |
| `--download` | flag | off | Save to `~/storage/shared/Download` (Termux) |
| `--group-size <N>` | int | `100` | Chapters per output HTML file |
| `--preserve-numbers` | flag | off | Keep original site chapter titles; skip renumbering |
| `--help` | flag | — | Show help and exit |

> **Note:** The minimum throttle of **3.0 seconds** is enforced regardless of
> the value passed via `--throttle`. FanMTL rate-limits aggressively.

---

## Examples

**Download all chapters**

```bash
php fanmtl/fanmtl.php --url "https://www.fanmtl.com/novel/6954524.html"
```

**Chapters 51 onwards, keep original titles**

```bash
php fanmtl/fanmtl.php \
  --url "https://www.fanmtl.com/novel/6954524.html" \
  --start 51 \
  --preserve-numbers
```

**Download to Termux Downloads folder**

```bash
php fanmtl/fanmtl.php \
  --url "https://www.fanmtl.com/novel/6954524.html" \
  --download
```

**First 10 chapters, custom output name**

```bash
php fanmtl/fanmtl.php \
  --url "https://www.fanmtl.com/novel/6954524.html" \
  --out "MyNovel" \
  --end 10
```

---

## How Chapter Lists Are Found

FanMTL uses a Readwn-style multi-page TOC. The scraper:

1. **Pagination detection** — scans pagination links for `?page=N` or
   `/page/N/` patterns, determines the maximum page number, then fetches
   every page.
2. **`rel="next"` following** — if no pagination numbers are found, follows
   `<a rel="next">` links until there are none.
3. **Fallback** — scans `//div[contains(@class,'chapter-list')]//a` and
   similar selectors on the main page.

On each TOC page, chapter links are extracted from:
- `//ul[contains(@class,'chapter-list')]//a`
- `//div[contains(@class,'chapter-list')]//a`
- `//div[contains(@class,'list-chapter')]//a`
- `//ul[contains(@class,'list-chapter')]//a`
- `//a[contains(@class,'chapter-link')]`

---

## Content Selectors (XPath)

| Data | Primary XPath |
|---|---|
| Novel title | `//h1[itemprop='name']` → `//h1` |
| Author | `//span[@itemprop='author']` |
| Summary | `//div[contains(@class,'summary')]//div[contains(@class,'content')]` |
| Cover | `//figure[contains(@class,'cover')]//img` |
| Chapter content | `//div[@id='chapter-content']` → `//div[contains(@class,'chapter-content')]` → `//div[contains(@class,'read-content')]` → `//div[contains(@class,'entry-content')]` → `//article` → `//main` |
| Chapter title | `.//*[contains(@class,'chapter-title')]` inside content block → `//h2` → `//h3` |

---

## Output

```
My Novel/
├── My Novel(1-100).html
├── My Novel(101-200).html
└── My Novel(201-250).html
```

Each file is a self-contained A5-optimized HTML document with:
- Embedded CSS (no external assets)
- Novel title, author, and summary in the header
- Sequential chapter articles with `id="ch-N"` anchors
- Datestamped footer: *Archived with FanMTL Scraper • YYYY-MM-DD*

---

## Notes

- The 3-second minimum throttle is strictly enforced. Passing `--throttle 1`
  will be silently raised to `3.0`.
- Chapter titles are renumbered `Chapter N: Short Title` by default.
  Pass `--preserve-numbers` to keep the original site titles.
- If the chapter list order appears to be descending (latest first), the
  scraper automatically reverses it to oldest-first.
