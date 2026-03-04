<?php
/**
 * NovelHall Scraper — A5 Novel Archiver (Single-file)
 *
 * - Scrapes novels from novelhall.com and outputs A5-optimized HTML files.
 * - Creates a folder named after the novel (or --out) in the current dir or Downloads (Termux).
 * - Splits the novel into parts (default 100 chapters per file). Files named:
 *     NovelTitle(1-100).html, NovelTitle(101-200).html, ...
 *
 * Selectors based on WebToEpub's NovelhallParser:
 *   - Chapter list : div.book-catalog  (picks the one with the most <a> elements)
 *   - Content      : article div.entry-content
 *   - Chapter title: article div.single-header h1
 *   - Novel title  : div.book-info h1
 *   - Author       : meta[property='books:author'] content
 *   - Summary      : div.book-info div.intro
 *   - Cover        : div.book-img img
 *
 * Usage:
 *   php novelhall.php --url "<URL>" [--out "Name"] [--start N] [--end N]
 *                     [--throttle 1.0] [--download] [--group-size 100]
 *                     [--preserve-numbers] [--help]
 *
 * Notes:
 *   - No external libraries. Uses cURL and DOMDocument.
 *   - Set --download to save in $HOME/storage/shared/Download when available (Termux).
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
mb_internal_encoding('UTF-8');

const ALLOWED_HOSTS = ['novelhall.com', 'www.novelhall.com'];
const BASE_URL      = 'https://www.novelhall.com';
const MINIMUM_THROTTLE = 1.0; // seconds

const A5_CSS = <<<'CSS'
@page { size: A5; margin: 18mm; }
html { font-size: 16px; }
body {
    font-family: "Libre Baskerville", "Georgia", "Times New Roman", serif;
    color: #000;
    background: #fff;
    margin: 0;
    padding: 0;
    line-height: 1.5;
    -webkit-print-color-adjust: exact;
}
.book {
    max-width: 148mm;
    margin: 0 auto;
    padding: 12mm;
    box-sizing: border-box;
}
header { text-align: center; margin-bottom: 12mm; }
h1 { font-size: 1.875rem; margin: 0 0 0.375rem 0; font-weight: 700; }
h2.author { font-size: 0.9375rem; margin: 0 0 0.625rem 0; font-weight: 400; color: #222; font-style: italic; }
.summary { font-size: 0.8125rem; color: #333; margin-bottom: 0.625rem; line-height: 1.4; }
hr.sep { border: none; border-top: 1px solid #ccc; margin: 0.625rem 0; }
.chapter { page-break-inside: avoid; margin-bottom: 0.75rem; }
.chapter + .chapter { margin-top: 0.5rem; }
.chapter-title { font-size: 0.9375rem; margin: 0.625rem 0 0.375rem 0; font-weight: 600; color: #111; }
.chapter-content { font-size: 0.9375rem; line-height: 1.6; text-align: justify; color: #111; hyphens: auto; }
.chapter-content p { margin: 0 0 0.625rem 0; text-indent: 1.25rem; }
.chapter-content p:first-child { text-indent: 0; }
footer { text-align: center; font-size: 0.75rem; color: #777; margin-top: 14mm; font-style: italic; }
CSS;

/* --------------------------- Globals & Flags --------------------------- */

$PRESERVE_NUMBERS = false;

/* --------------------------- Utilities --------------------------- */

function eprint(string $msg = ''): void {
    fwrite(STDERR, $msg . PHP_EOL);
}

function http_get(string $url, array $headers = [], int $timeout = 60): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NovelhallScraper/1.0)',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);
    if ($resp === false) {
        throw new RuntimeException("Network error: $err");
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP $httpCode: $url");
    }
    return $resp;
}

function throttle(float $seconds): void {
    if ($seconds > 0) usleep((int)($seconds * 1_000_000));
}

function sanitize_filename(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim(substr($name, 0, 240));
    if ($name === '') $name = 'novel';
    return $name;
}

function load_dom(string $html): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new DOMXPath($doc);
    libxml_clear_errors();
    return [$doc, $xpath];
}

function remove_nodes_by_xpath(DOMXPath $xpath, string $expr, ?DOMNode $context = null): void {
    $nodes = $xpath->query($expr, $context);
    if (!$nodes) return;
    foreach ($nodes as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
}

function inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function url_join(string $base, string $rel): string {
    if (preg_match('#^https?://#i', $rel)) return $rel;
    if ($rel === '') return $base;
    if (str_starts_with($rel, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return "$scheme:$rel";
    }
    $parts = parse_url($base);
    if (!$parts) return $rel;
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'] ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path   = $parts['path'] ?? '/';
    if (str_starts_with($rel, '/')) {
        return "$scheme://$host$port$rel";
    }
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($rel, '/');
    // Use (?<!:) to avoid collapsing '://' in the scheme.
    $abs = preg_replace('#(?<!:)(/\.?/)#', '/', $abs);
    while (preg_match('#/[^/]+/\.\./#', $abs)) {
        $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs, 1);
    }
    return $abs;
}

function extract_chapter_number_from_title(string $title): ?int {
    if (preg_match('/(?:Chapter|Chap|Ch)[^\d]{0,4}(\d+)/i', $title, $m)) return (int)$m[1];
    if (preg_match('/^\s*(\d{1,4})[\.\)\-\s:]/', $title, $m)) return (int)$m[1];
    if (preg_match('/\b([1-9][0-9]{0,3})\b/', $title, $m)) {
        $n = (int)$m[1];
        if ($n <= 10000) return $n;
    }
    return null;
}

function strip_leading_chapter_prefix(string $title): string {
    $t = preg_replace('/^\s*(?:Chapter|Chap|Ch)[\s\.\-:]*\d+[\s\.\-:]*\s*/i', '', $title);
    $t = preg_replace('/^\s*\d{1,4}[\.\)\-:\s]+\s*/', '', $t);
    return trim($t);
}

function clean_fragment_html(string $html, string $baseUrl = ''): string {
    [$doc, $xpath] = load_dom($html);
    remove_nodes_by_xpath($xpath, '//script|//style|//noscript|//comment()');
    $patterns = [
        "//*[contains(@class,'breadcrumb')]",
        "//*[contains(@class,'navbar')]",
        "//*[contains(@class,'btn')]",
        "//*[contains(@class,'nav')]",
        "//*[contains(@class,'share')]",
        "//*[contains(@class,'report')]",
        "//*[contains(@class,'rating')]",
        "//*[contains(@class,'comment')]",
        "//*[contains(@class,'ads')]", "//*[contains(@id,'ads')]",
        "//aside", "//footer", "//header", "//nav"
    ];
    foreach ($patterns as $p) remove_nodes_by_xpath($xpath, $p);
    if ($baseUrl !== '') {
        foreach ($xpath->query('//*[@src or @href]') as $el) {
            if ($el->hasAttribute('src')) {
                $v = $el->getAttribute('src');
                if ($v && !preg_match('#^(https?|data|mailto|tel|javascript):#i', $v)) {
                    $el->setAttribute('src', url_join($baseUrl, $v));
                }
            }
            if ($el->hasAttribute('href')) {
                $v = $el->getAttribute('href');
                if ($v && !preg_match('#^(https?|data|mailto|tel|javascript):#i', $v)) {
                    $el->setAttribute('href', url_join($baseUrl, $v));
                }
            }
        }
    }
    foreach ($xpath->query('//*') as $el) {
        $keep = [];
        foreach (['href', 'src', 'alt', 'title'] as $attr) {
            if ($el->hasAttribute($attr)) $keep[$attr] = $el->getAttribute($attr);
        }
        if ($el->hasAttributes()) {
            $attrs = [];
            foreach ($el->attributes as $a) $attrs[] = $a->name;
            foreach ($attrs as $an) $el->removeAttribute($an);
        }
        foreach ($keep as $k => $v) $el->setAttribute($k, $v);
    }
    $body = $xpath->query('//body')->item(0);
    return $body ? inner_html($body) : $html;
}

/* --------------------------- Chapter fetching --------------------------- */

/**
 * Fetch and extract a single chapter's title and content from a NovelHall chapter page.
 *
 *  - Title  : article div.single-header h1
 *  - Content: article div.entry-content
 */
function fetch_chapter_content(string $url, float $throttle = MINIMUM_THROTTLE): array {
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
    throttle($throttle);
    $html = http_get($url);
    [$doc, $xpath] = load_dom($html);

    // Chapter title: article div.single-header h1 (NovelhallParser: findChapterTitle)
    $foundTitle = '';
    $titleNode = $xpath->query("//article//div[contains(@class,'single-header')]//h1")->item(0)
              ?? $xpath->query("//div[contains(@class,'single-header')]//h1")->item(0)
              ?? $xpath->query("//article//h1")->item(0);
    if ($titleNode) {
        $foundTitle = trim(preg_replace('/\s+/', ' ', $titleNode->textContent));
    }

    // Content: article div.entry-content (NovelhallParser: findContent)
    $candidates = [
        "//article//div[contains(@class,'entry-content')]",
        "//div[contains(@class,'entry-content')]",
        "//article",
        "//div[@id='htmlContent']",
        "//div[contains(@class,'chapter-content')]",
        "//div[contains(@class,'read-content')]",
        "//main",
    ];

    $bestHtml  = '';
    $bestScore = 0;

    foreach ($candidates as $xp) {
        $nodes = @$xpath->query($xp);
        if (!$nodes || $nodes->length === 0) continue;
        foreach ($nodes as $node) {
            remove_nodes_by_xpath($xpath, ".//form|.//button|.//input|.//textarea|.//*[contains(@class,'comment')]|.//*[contains(@class,'share')]", $node);
            $text   = trim($node->textContent);
            $pCount = $xpath->query('.//p', $node)->length;
            $score  = mb_strlen($text) + $pCount * 400;
            if ($text !== '' && mb_strlen($text) > 60 && $score > $bestScore) {
                $bestScore = $score;
                $bestHtml  = inner_html($node);
            }
        }
        if ($bestScore > 0) break;
    }

    if ($bestScore === 0) {
        $body = $xpath->query('//body')->item(0);
        if ($body) {
            remove_nodes_by_xpath($xpath, ".//script|.//style|.//nav|.//footer", $body);
            $bestHtml = inner_html($body);
        }
    }

    $clean = clean_fragment_html($bestHtml, $url);
    return ['title' => $foundTitle, 'content' => $clean];
}

/* --------------------------- Novel page parsing --------------------------- */

/**
 * Parse the main novel page to extract metadata and the chapter list.
 *
 * Metadata selectors (NovelhallParser):
 *   - Title  : div.book-info h1
 *   - Author : meta[property='books:author'] content
 *   - Summary: div.book-info div.intro
 *   - Cover  : div.book-img img (findCoverImageUrl)
 *
 * Chapter list (NovelhallParser: getChapterUrls):
 *   Find all div.book-catalog elements, map each to its <a> children,
 *   keep the list with the most entries (the expanded TOC).
 */
function parse_novel_page(string $url, float $throttle = MINIMUM_THROTTLE): array {
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
    eprint("Fetching novel page: $url");
    $html = http_get($url);
    [$doc, $xpath] = load_dom($html);

    $novel = [
        'url'      => $url,
        'title'    => '',
        'author'   => '',
        'summary'  => '',
        'cover'    => '',
        'chapters' => [],
    ];

    // Title: div.book-info h1
    $h1 = $xpath->query("//div[contains(@class,'book-info')]//h1")->item(0);
    if ($h1) $novel['title'] = trim(preg_replace('/\s+/', ' ', $h1->textContent));

    // Author: <meta property="books:author"> content (NovelhallParser: extractAuthor)
    $meta = $xpath->query("//meta[@property='books:author']")->item(0);
    if ($meta) {
        $novel['author'] = trim($meta->getAttribute('content'));
    }
    if ($novel['author'] === '') {
        $authorNode = $xpath->query("//div[contains(@class,'book-info')]//*[contains(@class,'author')]")->item(0);
        if ($authorNode) $novel['author'] = trim($authorNode->textContent);
    }

    // Summary: div.book-info div.intro (NovelhallParser: getInformationEpubItemChildNodes)
    $introNode = $xpath->query("//div[contains(@class,'book-info')]//div[contains(@class,'intro')]")->item(0);
    if ($introNode) $novel['summary'] = trim($introNode->textContent);

    // Cover: div.book-img img (NovelhallParser: findCoverImageUrl)
    $coverImg = $xpath->query("//div[contains(@class,'book-img')]//img")->item(0);
    if ($coverImg) $novel['cover'] = $coverImg->getAttribute('src') ?: '';

    // Chapter list: pick div.book-catalog with the most <a> elements
    // (NovelhallParser: .reduce((a, c) => a.length < c.length ? c : a, []))
    $catalogs = $xpath->query("//div[contains(@class,'book-catalog')]");
    $bestLinks = [];
    if ($catalogs && $catalogs->length > 0) {
        foreach ($catalogs as $catalog) {
            $links = $xpath->query(".//a", $catalog);
            if ($links && $links->length > count($bestLinks)) {
                $bestLinks = [];
                foreach ($links as $link) $bestLinks[] = $link;
            }
        }
    }

    $seen = [];
    foreach ($bestLinks as $link) {
        $href = trim($link->getAttribute('href'));
        $text = trim($link->textContent);
        if (!$href || !$text) continue;
        $href = url_join($url, $href);
        if (isset($seen[$href])) continue;
        $seen[$href] = true;
        $novel['chapters'][] = ['name' => $text, 'url' => $href];
    }

    // Fallback: scan for chapter links generically
    if (empty($novel['chapters'])) {
        eprint("Fallback: scraping chapter links from page...");
        $selectors = [
            "//ul[contains(@class,'chapter-list')]//a",
            "//div[contains(@class,'chapter-list')]//a",
            "//a[contains(@href,'/chapter') or contains(@href,'/html/')]",
        ];
        foreach ($selectors as $sel) {
            foreach ($xpath->query($sel) as $a) {
                $href = trim($a->getAttribute('href'));
                $text = trim($a->textContent);
                if (!$href || !$text) continue;
                $href = url_join($url, $href);
                if (isset($seen[$href])) continue;
                $seen[$href] = true;
                $novel['chapters'][] = ['name' => $text, 'url' => $href];
            }
            if (!empty($novel['chapters'])) break;
        }
    }

    // If list appears to be in descending order, reverse to oldest-first
    $nums     = [];
    $firstNum = null;
    $lastNum  = null;
    foreach ($novel['chapters'] as $ch) {
        $nums[] = extract_chapter_number_from_title($ch['name'] ?? '');
    }
    foreach ($nums as $n) { if ($n !== null) { $firstNum = $n; break; } }
    for ($i = count($nums) - 1; $i >= 0; $i--) { if ($nums[$i] !== null) { $lastNum = $nums[$i]; break; } }
    if ($firstNum !== null && $lastNum !== null && $firstNum > $lastNum) {
        $novel['chapters'] = array_reverse($novel['chapters']);
    }

    return $novel;
}

/* --------------------------- HTML builder --------------------------- */

function build_a5_html(array $novel): string {
    $title   = htmlspecialchars($novel['title'] ?: 'Untitled Novel', ENT_QUOTES | ENT_HTML5);
    $author  = htmlspecialchars($novel['author'] ?? '', ENT_QUOTES | ENT_HTML5);
    $summary = nl2br(htmlspecialchars($novel['summary'] ?? '', ENT_QUOTES | ENT_HTML5));
    $html    = "<!doctype html>\n<html lang='en'>\n<head>\n<meta charset='utf-8'>\n<meta name='viewport' content='width=device-width,initial-scale=1'>\n<title>{$title}</title>\n<style>" . A5_CSS . "</style>\n</head>\n<body>\n<div class='book'>\n<header>\n";
    $html   .= "<h1>{$title}</h1>\n";
    if ($author !== '') $html .= "<h2 class='author'>{$author}</h2>\n";
    if ($summary !== '') $html .= "<div class='summary'>{$summary}</div>\n";
    $html   .= "<hr class='sep'>\n</header>\n";

    foreach ($novel['chapters'] as $i => $ch) {
        $displayTitle = trim($ch['name'] ?? '');
        if ($displayTitle === '') $displayTitle = 'Chapter ' . ($i + 1);
        $safeTitle = htmlspecialchars($displayTitle, ENT_QUOTES | ENT_HTML5);
        $content   = $ch['content'] ?? '<p><em>(no content)</em></p>';
        $content   = preg_replace('#<a[^>]*>(?:Prev|Next|Comments?|Report|Home|Novel|Table of Contents?)</a>#is', '', $content);
        $html .= "<article class='chapter' id='ch-" . ($i + 1) . "'>\n";
        $html .= "  <h3 class='chapter-title'>{$safeTitle}</h3>\n";
        $html .= "  <div class='chapter-content'>{$content}</div>\n</article>\n<hr class='sep'>\n";
    }

    $html .= "<footer>Archived with NovelHall Scraper • " . date('Y-m-d') . "</footer>\n</div>\n</body>\n</html>\n";
    return $html;
}

/* --------------------------- CLI / MAIN --------------------------- */

function show_help(): void {
    $name = basename(__FILE__);
    fwrite(STDOUT, <<<TXT
NovelHall Scraper — A5 Novel Archiver (Single-file)

Usage:
  php $name --url <URL> [--out <name>] [--start N] [--end N] [--throttle SEC] [--download] [--group-size N] [--preserve-numbers] [--help]

Options:
  --url              Novel main page URL (novelhall.com)
  --out              Output name for folder/filename (sanitized). If omitted uses novel title.
  --start            First chapter (1-based)
  --end              Last chapter (1-based)
  --throttle         Delay between requests in seconds (default: 1.0, minimum enforced)
  --download         Save to ~/storage/shared/Download if available (Termux)
  --group-size       Number of chapters per part (default: 100)
  --preserve-numbers Keep original site chapter titles (no renumbering)
  --help             Show this help

TXT
    );
    exit(0);
}

$options = getopt('', ['url:', 'out:', 'start:', 'end:', 'throttle:', 'download', 'group-size:', 'preserve-numbers', 'help']);
if (isset($options['help'])) show_help();

$url       = $options['url'] ?? null;
$throttle  = floatval($options['throttle'] ?? MINIMUM_THROTTLE);
if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
$out       = $options['out'] ?? null;
$start     = isset($options['start']) ? (int)$options['start'] : null;
$end       = isset($options['end'])   ? (int)$options['end']   : null;
$download  = !empty($options['download']);
$groupSize = isset($options['group-size']) ? max(1, (int)$options['group-size']) : 100;
$PRESERVE_NUMBERS = isset($options['preserve-numbers']) || $PRESERVE_NUMBERS;

if (!$url) {
    eprint("=== NovelHall Scraper — A5 Archiver ===");
    echo "Novel URL: "; $url = trim(fgets(STDIN));
    if (!$url) exit(1);
    echo "Throttle (s) [1.0]: "; $t = trim(fgets(STDIN)); if ($t !== '') $throttle = (float)$t;
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
    echo "Output name [auto]: "; $o = trim(fgets(STDIN)); if ($o !== '') $out = $o;
    echo "Start chapter [1]: "; $s = trim(fgets(STDIN)); if ($s !== '') $start = (int)$s;
    echo "End chapter [last]: "; $e = trim(fgets(STDIN)); if ($e !== '') $end = (int)$e;
    echo "Save to Downloads? (y/N): "; $d = trim(fgets(STDIN)); if (in_array(strtolower($d), ['y', 'yes'])) $download = true;
    echo "Group size (chapters per file) [100]: "; $g = trim(fgets(STDIN)); if ($g !== '') $groupSize = max(1, (int)$g);
    echo "Preserve site numbering? (y/N): "; $p = trim(fgets(STDIN)); if (in_array(strtolower($p), ['y', 'yes'])) $PRESERVE_NUMBERS = true;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host || !in_array(strtolower($host), ALLOWED_HOSTS, true)) {
    eprint("Error: Unsupported host '$host'. Only novelhall.com allowed.");
    exit(1);
}

try {
    $novel = parse_novel_page($url, $throttle);
    if (empty($novel['chapters'])) throw new RuntimeException('No chapters found.');

    $total    = count($novel['chapters']);
    $startIdx = max(0, ($start ?: 1) - 1);
    $endIdx   = min($total - 1, ($end ?: $total) - 1);
    if ($endIdx < $startIdx) $endIdx = $startIdx;

    eprint("Fetching chapters " . ($startIdx + 1) . " to " . ($endIdx + 1) . " (throttle: {$throttle}s)");

    for ($i = $startIdx; $i <= $endIdx; $i++) {
        $ch = $novel['chapters'][$i];
        if (!isset($ch['content']) || trim($ch['content']) === '') {
            $displayIdx = $i + 1;
            $name = $ch['name'] ?: "Chapter " . $displayIdx;
            eprint("[" . $displayIdx . "] $name");
            $res = fetch_chapter_content($ch['url'], $throttle);
            if (!empty($res['title'])) {
                $novel['chapters'][$i]['name'] = $res['title'];
            }
            $novel['chapters'][$i]['content'] = $res['content'] ?: '<p><em>(empty chapter)</em></p>';
        }
    }

    // Trim to requested range
    $novel['chapters'] = array_slice($novel['chapters'], $startIdx, $endIdx - $startIdx + 1);

    // Sequential chapter renumbering (unless --preserve-numbers)
    if (!$PRESERVE_NUMBERS) {
        $startChapter = $start ?: 1;
        $chapterCount = count($novel['chapters']);
        for ($i = 0; $i < $chapterCount; $i++) {
            $chapterNumber = $startChapter + $i;
            $origName = $novel['chapters'][$i]['name'] ?? '';
            $short    = strip_leading_chapter_prefix($origName);
            if ($short === '') $short = trim(preg_replace('/\s+/', ' ', $origName)) ?: '';
            if ($short !== '') {
                $novel['chapters'][$i]['name'] = 'Chapter ' . $chapterNumber . ': ' . $short;
            } else {
                $novel['chapters'][$i]['name'] = 'Chapter ' . $chapterNumber;
            }
        }
    }

    // Determine base directory
    $baseDir = getcwd();
    $shared  = getenv('HOME') . '/storage/shared/Download';
    if ($download && is_dir($shared) && is_writable($shared)) $baseDir = $shared;

    // Determine folder name
    $folderName = $out ? sanitize_filename($out) : sanitize_filename($novel['title'] ?: 'novel');
    $novelDir   = rtrim($baseDir, '/') . '/' . $folderName;
    if (!is_dir($novelDir)) {
        if (!mkdir($novelDir, 0777, true) && !is_dir($novelDir)) {
            throw new RuntimeException("Failed to create directory: $novelDir");
        }
    }

    // Split into groups and save
    $chunks        = array_chunk($novel['chapters'], $groupSize);
    $chapterOffset = $start ?: 1;

    foreach ($chunks as $idx => $group) {
        $groupStart = $chapterOffset + $idx * $groupSize;
        $groupEnd   = $groupStart + count($group) - 1;

        $partNovel             = $novel;
        $partNovel['chapters'] = $group;

        $html = build_a5_html($partNovel);

        $fileBase = sanitize_filename($out ? $out : ($novel['title'] ?: 'novel'));
        $filename = sprintf('%s/%s(%d-%d).html', $novelDir, $fileBase, $groupStart, $groupEnd);

        file_put_contents($filename, $html);
        eprint("✅ Saved: $filename");
    }

    eprint("✅ All parts saved in: $novelDir");
    exit(0);

} catch (Throwable $e) {
    eprint("❌ Error: " . $e->getMessage());
    exit(1);
}
