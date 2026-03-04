# Contributing

Thank you for your interest in contributing to the Novel Scraper Collection!

---

## Table of Contents

1. [Project Structure](#project-structure)
2. [Coding Conventions](#coding-conventions)
3. [Adding a New Scraper](#adding-a-new-scraper)
4. [Running the Tests](#running-the-tests)
5. [Adding Tests](#adding-tests)
6. [Submitting a Pull Request](#submitting-a-pull-request)

---

## Project Structure

```
novelbin-scraper/
├── novelbin/
│   ├── novelbin.php     ← NovelBin scraper
│   └── README.md        ← Scraper-specific docs
├── fanmtl/
│   ├── fanmtl.php       ← FanMTL scraper
│   └── README.md
├── novelhall/
│   ├── novelhall.php    ← NovelHall scraper
│   └── README.md
├── tests.php            ← Unit test suite (shared utilities)
├── README.md            ← Project overview
├── CONTRIBUTING.md      ← This file
└── LICENSE.md
```

Each scraper is a **single self-contained PHP file** with no external
dependencies. Shared logic (URL joining, DOM loading, HTML cleaning, etc.)
is duplicated across files intentionally to keep each script independently
usable — you can hand a single `.php` file to someone and it will just work.

---

## Coding Conventions

### PHP version

Target **PHP 8.0+**. Use named arguments, nullsafe operators, and `str_contains` /
`str_starts_with` where they improve readability.

### Strict types

Every file must begin with:

```php
declare(strict_types=1);
```

### Constants

Site-specific constants are declared at the top of each file:

```php
const ALLOWED_HOSTS    = ['example.com', 'www.example.com'];
const BASE_URL         = 'https://www.example.com';
const MINIMUM_THROTTLE = 1.0; // seconds
```

### Functions

- All utility functions (`eprint`, `http_get`, `throttle`, `sanitize_filename`,
  `load_dom`, `remove_nodes_by_xpath`, `inner_html`, `url_join`,
  `extract_chapter_number_from_title`, `strip_leading_chapter_prefix`,
  `clean_fragment_html`) are copied verbatim from an existing scraper and
  adjusted only where the new site requires it.
- Do **not** call `curl_close()` — it is a no-op since PHP 8.0 and deprecated
  in PHP 8.5.
- `url_join` must use the `(?<!:)` lookbehind in its path-normalization regex
  to avoid corrupting `https://`.

### `getopt` options

Use `:` (value required) for options that take a parameter, and bare names
for boolean flags:

```php
$options = getopt('', ['url:', 'out:', 'start:', 'end:', 'throttle:',
                       'download', 'group-size:', 'preserve-numbers', 'help']);
```

Using `::` (optional value) breaks one-liner CLI usage.

### Throttle

Call `throttle()` once at the top of `fetch_chapter_content()`. Do not add
an additional `throttle()` call at the call site.

### Minimum throttle

Each scraper enforces its own minimum. Clamp the value before use:

```php
if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
```

### Chapter renumbering

The default behaviour is to renumber chapters as `Chapter N: Short Title`.
Always provide a `--preserve-numbers` flag to opt out.

---

## Adding a New Scraper

1. **Create the directory and file:**

   ```bash
   mkdir <sitename>
   touch <sitename>/<sitename>.php
   ```

2. **Copy the template from the closest existing scraper** (NovelHall is the
   simplest and most recent; use it as the starting point).

3. **Update the constants** at the top:

   ```php
   const ALLOWED_HOSTS    = ['newsite.com', 'www.newsite.com'];
   const BASE_URL         = 'https://www.newsite.com';
   const MINIMUM_THROTTLE = 1.0;
   ```

4. **Implement `parse_novel_page()`:**

   - Identify the XPath selectors for title, author, summary, cover, and
     chapter links by inspecting the site HTML.
   - Implement the chapter-list strategy appropriate for the site
     (AJAX, pagination, static block, etc.).

5. **Implement `fetch_chapter_content()`:**

   - Identify the content container and chapter title selectors.
   - Add them as the first entries in `$candidates`.

6. **Update the footer brand string** in `build_a5_html()`:

   ```php
   $html .= "... Archived with NewSite Scraper • " . date('Y-m-d') . " ...";
   ```

7. **Update `show_help()`** with the correct script name and site URL.

8. **Create `<sitename>/README.md`** documenting:
   - Supported domains
   - All CLI options
   - Usage examples
   - How chapter lists are found
   - Content selectors table

9. **Update the root `README.md`** to add a row to the scraper table and a
   brief section under "Scraper Reference".

10. **Verify syntax:**

    ```bash
    php -l <sitename>/<sitename>.php
    ```

11. **Run the test suite** to make sure you haven't broken shared utilities:

    ```bash
    php tests.php
    ```

---

## Running the Tests

```bash
php tests.php
```

Expected output:

```
Results: 20 passed / 20 total
```

The tests cover the shared utility functions that are common to all scrapers:
`sanitize_filename`, `url_join`, `throttle`, `load_dom`, `inner_html`,
`remove_nodes_by_xpath`, and `clean_fragment_html`.

---

## Adding Tests

Tests live in `tests.php`. Each test group follows this pattern:

```php
test_group('my_function', function() {
    assert_equal('my_function returns foo', my_function('input'), 'expected');
    assert_true('my_function is truthy', (bool)my_function('other'));
});
```

Helper functions available:

| Function | Description |
|---|---|
| `assert_equal($label, $actual, $expected)` | Strict equality check |
| `assert_true($label, $condition)` | Boolean truth check |
| `assert_false($label, $condition)` | Boolean false check |
| `assert_contains($label, $haystack, $needle)` | Substring check |
| `assert_not_contains($label, $haystack, $needle)` | Negative substring check |

When adding a new scraper, if it introduces a new utility function, add tests
for that function following the same style.

---

## Submitting a Pull Request

1. Fork the repository and create a feature branch:
   ```bash
   git checkout -b feat/add-newsite-scraper
   ```
2. Make your changes following the conventions above.
3. Run `php -l` on every changed `.php` file.
4. Run `php tests.php` and confirm all tests pass.
5. Open a pull request with a descriptive title and a brief summary of what
   was added or changed.
