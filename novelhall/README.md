# NovelHall Scraper

Single-file PHP scraper for **NovelHall**.  
Script: `novelhall/novelhall.php`

Selectors derived from WebToEpub's `NovelhallParser`.

---

## Supported Domains

| Domain | Notes |
|---|---|
| `novelhall.com` | primary |
| `www.novelhall.com` | www prefix |

---

## Usage

### One-liner

```bash
php novelhall/novelhall.php --url "<URL>" [OPTIONS]
```

### Interactive mode

```bash
php novelhall/novelhall.php
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
| `--throttle <sec>` | float | `1.0` | Seconds to wait between requests (minimum 1.0 enforced) |
| `--download` | flag | off | Save to `~/storage/shared/Download` (Termux) |
| `--group-size <N>` | int | `100` | Chapters per output HTML file |
| `--preserve-numbers` | flag | off | Keep original site chapter titles; skip renumbering |
| `--help` | flag | — | Show help and exit |

---

## Examples

**Download all chapters**

```bash
php novelhall/novelhall.php \
  --url "https://www.novelhall.com/a-novel-name-12345/"
```

**Chapters 1 – 50**

```bash
php novelhall/novelhall.php \
  --url "https://www.novelhall.com/a-novel-name-12345/" \
  --start 1 --end 50
```

**Keep original titles, save to Downloads (Termux)**

```bash
php novelhall/novelhall.php \
  --url "https://www.novelhall.com/a-novel-name-12345/" \
  --preserve-numbers \
  --download
```

**Custom output name, 25-chapter parts**

```bash
php novelhall/novelhall.php \
  --url "https://www.novelhall.com/a-novel-name-12345/" \
  --out "MyNovel" \
  --group-size 25
```

---

## How Chapter Lists Are Found

NovelHall places the full chapter index in one or more `div.book-catalog`
blocks on the novel's main page. Some pages have two blocks — a collapsed
preview and a full list. The scraper:

1. Collects all `div.book-catalog` elements.
2. For each block, counts its `<a>` children.
3. **Keeps the block with the most links** (mirrors WebToEpub's
   `.reduce((a, c) => a.length < c.length ? c : a, [])` logic).
4. Falls back to generic `ul.chapter-list a` and `/html/` href scanning if
   no `book-catalog` blocks are found.

---

## Content Selectors (XPath)

| Data | Selector |
|---|---|
| Novel title | `//div[contains(@class,'book-info')]//h1` |
| Author | `//meta[@property='books:author'] @content` |
| Summary | `//div[contains(@class,'book-info')]//div[contains(@class,'intro')]` |
| Cover | `//div[contains(@class,'book-img')]//img @src` |
| Chapter title | `//article//div[contains(@class,'single-header')]//h1` |
| Chapter content | `//article//div[contains(@class,'entry-content')]` |

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
- Datestamped footer: *Archived with NovelHall Scraper • YYYY-MM-DD*

---

## Notes

- The minimum throttle of **1.0 second** is enforced.
- Chapter titles are renumbered `Chapter N: Short Title` by default.
  Pass `--preserve-numbers` to keep the original site titles.
- If the chapter list order appears to be descending (latest first), the
  scraper automatically reverses it to oldest-first.
