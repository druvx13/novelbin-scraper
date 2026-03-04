# NovelBin Scraper

Single-file PHP scraper for **NovelBin** family sites.  
Script: `novelbin/novelbin.php`

---

## Supported Domains

| Domain | Alias / Mirror |
|---|---|
| `novelbin.com` | primary |
| `www.novelbin.com` | www prefix |
| `novelbin.org` | alternate TLD |
| `www.novelbin.org` | www prefix |
| `thenovelbin.org` | mirror |
| `www.thenovelbin.org` | www prefix |
| `novlove.com` | mirror |
| `www.novlove.com` | www prefix |

---

## Usage

### One-liner

```bash
php novelbin/novelbin.php --url "<URL>" [OPTIONS]
```

### Interactive mode

```bash
php novelbin/novelbin.php
```

Prompts for URL, throttle, output name, chapter range, download flag, and group size.

---

## Options

| Option | Type | Default | Description |
|---|---|---|---|
| `--url <URL>` | string | — | Novel main page URL **(required)** |
| `--out <name>` | string | novel title | Output folder / filename base |
| `--start <N>` | int | `1` | First chapter to download (1-based) |
| `--end <N>` | int | last | Last chapter to download (1-based) |
| `--throttle <sec>` | float | `1.0` | Seconds to wait between requests |
| `--download` | flag | off | Save to `~/storage/shared/Download` (Termux) |
| `--group-size <N>` | int | `100` | Chapters per output HTML file |
| `--help` | flag | — | Show help and exit |

---

## Examples

**Download all chapters**

```bash
php novelbin/novelbin.php --url "https://novelbin.com/b/super-gene"
```

**Chapters 51 – 150, saved to Downloads (Termux)**

```bash
php novelbin/novelbin.php \
  --url "https://novelbin.com/b/super-gene" \
  --start 51 --end 150 \
  --download
```

**Custom folder name, 2-second throttle**

```bash
php novelbin/novelbin.php \
  --url "https://novelbin.com/b/versatile-mage" \
  --out "VMage" \
  --throttle 2.0
```

**50-chapter parts, first 200 chapters**

```bash
php novelbin/novelbin.php \
  --url "https://novelbin.com/b/overgeared" \
  --end 200 \
  --group-size 50
```

---

## How Chapter Lists Are Found

1. **AJAX endpoint (primary)** — the page HTML contains
   `data-novel-id="<id>"`. The scraper requests
   `/ajax/chapter-archive?novelId=<id>` and parses
   `ul.list-chapter li a`.
2. **Static fallback** — if the AJAX request fails or returns nothing,
   the scraper scans:
   - `//div[contains(@class,'list-chapter')]//a`
   - `//ul[contains(@class,'chapter-list')]//a`
   - `//a[contains(@href,'/chapter')]`

---

## Content Selectors (XPath)

| Data | Primary XPath |
|---|---|
| Novel title | `//div[contains(@class,'book')]//img @alt` → `//h1` |
| Author | `//ul[contains(@class,'info')]/li` with `h3` = "Author" |
| Summary | `//div[contains(@class,'desc-text')]` |
| Cover | `//div[contains(@class,'book')]//img @src` |
| Chapter content | `//div[@id='chr-content']` → `//div[contains(@class,'chr-c')]` → `//div[contains(@class,'chapter-content')]` → `//article` → `//main` |
| Chapter title | First `h1` / `h2` / `h3` / `h4` inside content block |
| Embedded chapters | `//div[contains(@class,'chapter') and starts-with(@id,'chapter-')]` |

---

## Output

```
Super Gene/
├── Super Gene(1-100).html
├── Super Gene(101-200).html
└── Super Gene(201-300).html
```

Each file is a self-contained A5-optimized HTML document with:
- Embedded CSS (no external assets)
- Novel title, author, and summary in the header
- Sequential chapter articles with `id="ch-N"` anchors
- Datestamped footer: *Archived with NovelBin Scraper • YYYY-MM-DD*

---

## Notes

- The minimum throttle is **not** enforced at a hard floor for this scraper
  (unlike FanMTL). Use `--throttle 1.0` or higher to avoid 429 errors.
- If the chapter list order appears to be descending (latest first), the
  scraper automatically reverses it to oldest-first.
- Chapters already embedded in the main page HTML (rare) are detected and
  used without making extra requests.
