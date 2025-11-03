#!/usr/bin/env php
<?php
/**
 * NovelBin Scraper — Perfect A5 Novel Archiver (Split into parts)
 *
 * - Scrapes novels from NovelBin domains and outputs A5-optimized HTML files.
 * - Creates a folder named after the novel (or --out) in current dir or Downloads (Termux).
 * - Splits the novel into parts (default 100 chapters per file). Files named:
 *     NovelTitle(1-100).html, NovelTitle(101-200).html, ...
 *
 * Usage:
 *   php novelbin.php --url "<URL>" [--out "Name"] [--start N] [--end N]
 *                    [--throttle 1.0] [--download] [--group-size 100] [--help]
 *
 * Notes:
 *   - No external libraries. Uses cURL and DOMDocument.
 *   - Set --download to save in $HOME/storage/shared/Download when available (Termux).
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
mb_internal_encoding('UTF-8');

const ALLOWED_HOSTS = [
    'novelbin.org', 'www.novelbin.org',
    'thenovelbin.org', 'www.thenovelbin.org',
    'novelbin.com', 'www.novelbin.com',
    'novlove.com', 'www.novlove.com'
];

const BASE_URL = 'https://novelbin.org';

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
h1 {
    font-size: 1.875rem;
    margin: 0 0 0.375rem 0;
    font-weight: 700;
    letter-spacing: -0.02em;
}
h2.author {
    font-size: 0.9375rem;
    margin: 0 0 0.625rem 0;
    font-weight: 400;
    color: #222;
    font-style: italic;
}
.summary {
    font-size: 0.8125rem;
    color: #333;
    margin-bottom: 0.625rem;
    line-height: 1.4;
}
hr.sep {
    border: none;
    border-top: 1px solid #ccc;
    margin: 0.625rem 0;
}
.chapter {
    page-break-inside: avoid;
    margin-bottom: 0.75rem;
}
.chapter + .chapter { margin-top: 0.5rem; }
.chapter-title {
    font-size: 0.9375rem;
    margin: 0.625rem 0 0.375rem 0;
    font-weight: 600;
    color: #111;
}
.chapter-content {
    font-size: 0.9375rem;
    line-height: 1.6;
    text-align: justify;
    color: #111;
    hyphens: auto;
}
.chapter-content p {
    margin: 0 0 0.625rem 0;
    text-indent: 1.25rem;
}
.chapter-content p:first-child { text-indent: 0; }
footer {
    text-align: center;
    font-size: 0.75rem;
    color: #777;
    margin-top: 14mm;
    font-style: italic;
}
CSS;

/* --------------------------- Utilities --------------------------- */

/**
 * Print to STDERR.
 */
function eprint(string $msg = ''): void {
    fwrite(STDERR, $msg . PHP_EOL);
}

/**
 * Simple HTTP GET with cURL.
 */
function http_get(string $url, array $headers = [], int $timeout = 60): string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; NovelBinScraper/2.1)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException("Network error: $err");
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP $httpCode: $url");
    }
    return $resp;
}

/**
 * Sleep for fractional seconds.
 */
function throttle(float $seconds): void {
    if ($seconds > 0) usleep((int)($seconds * 1_000_000));
}

/**
 * Sanitize string to safe filename.
 */
function sanitize_filename(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim(substr($name, 0, 240));
    if ($name === '') $name = 'novel';
    return $name;
}

/**
 * Load HTML into DOMDocument and create XPath.
 *
 * Returns [DOMDocument, DOMXPath].
 */
function load_dom(string $html): array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    // force utf-8
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $xpath = new DOMXPath($doc);
    libxml_clear_errors();
    return [$doc, $xpath];
}

/**
 * Remove nodes matching XPath.
 */
function remove_nodes_by_xpath(DOMXPath $xpath, string $expr, ?DOMNode $context = null): void {
    $nodes = $xpath->query($expr, $context);
    if (!$nodes) return;
    foreach ($nodes as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
    }
}

/**
 * Inner HTML of a node.
 */
function inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

/**
 * Join base URL and relative URL.
 */
function url_join(string $base, string $rel): string {
    if (preg_match('#^https?://#i', $rel)) return $rel;
    if (str_starts_with($rel, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return "$scheme:$rel";
    }
    $parts = parse_url($base);
    if (!$parts) return $rel;
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if (str_starts_with($rel, '/')) {
        return "$scheme://$host$port$rel";
    }
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($rel, '/');
    $abs = preg_replace('#(/\.?/)#', '/', $abs);
    while (preg_match('#/[^/]+/\.\./#', $abs)) {
        $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs, 1);
    }
    return $abs;
}

/**
 * Clean an HTML fragment: remove scripts/styles, strip many classes, fix relative links.
 */
function clean_fragment_html(string $html, string $baseUrl = ''): string {
    [$doc, $xpath] = load_dom($html);
    remove_nodes_by_xpath($xpath, '//script|//style|//noscript|//comment()');
    $patterns = [
        "//*[contains(@class,'breadcrumb')]",
        "//*[contains(@class,'navbar')]",
        "//*[contains(@class,'btn')]",
        "//*[contains(@class,'nav')]",
        "//*[contains(@class,'chr-nav')]",
        "//*[contains(@class,'novel-title')]",
        "//*[contains(@class,'toggle-nav-open')]",
        "//*[contains(@class,'report')]",
        "//*[contains(@class,'comment')]",
        "//*[contains(@class,'close-popup')]",
        "//*[contains(@class,'share')]",
        "//*[contains(@class,'rating')]",
        "//*[contains(@class,'pf-')]",
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
 * Try to find chapter content from a chapter page.
 * Returns ['title' => string, 'content' => string]
 */
function fetch_chapter_content(string $url, float $throttle = 1.0): array {
    throttle($throttle);
    $html = http_get($url);
    [$doc, $xpath] = load_dom($html);
    $candidates = [
        "//*[@id='chr-content']",
        "//*[contains(@class,'chr-c')]",
        "//*[@id='chapter-content']",
        "//*[contains(@class,'chapter-content')]",
        "//*[contains(@class,'entry-content')]",
        "//article", "//main"
    ];
    $bestHtml = '';
    $bestScore = 0;
    $foundTitle = '';
    foreach ($candidates as $xp) {
        $nodes = @$xpath->query($xp);
        if (!$nodes || $nodes->length === 0) continue;
        foreach ($nodes as $node) {
            remove_nodes_by_xpath($xpath, ".//form|.//button|.//input|.//textarea|.//*[contains(@class,'comment')]|.//*[contains(@class,'share')]", $node);
            $titleNode = null;
            foreach (['.//h1', './/h2', './/h3', './/h4'] as $tq) {
                $tqNodes = $xpath->query($tq, $node);
                if ($tqNodes && $tqNodes->length) {
                    $titleNode = $tqNodes->item(0);
                    break;
                }
            }
            if ($titleNode) {
                $foundTitle = trim(preg_replace('/\s+/', ' ', $titleNode->textContent));
                $titleNode->parentNode?->removeChild($titleNode);
                $foundTitle = preg_replace('/^(Chapter\s+\d+\s*[:\-—|]\s*)(Chapter\s+\d+\s*[:\-—|])/i', '$2', $foundTitle);
                $foundTitle = trim($foundTitle);
            }
            $text = trim($node->textContent);
            $pCount = $xpath->query('.//p', $node)->length;
            $score = mb_strlen($text) + $pCount * 500;
            if (($text !== '' && mb_strlen($text) > 100) && $score > $bestScore) {
                $bestScore = $score;
                $bestHtml = inner_html($node);
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
 * Extract embedded chapters from main page (if any).
 */
function extract_embedded_chapters(string $html, string $baseUrl): array {
    [$doc, $xpath] = load_dom($html);
    $chapters = [];
    foreach ($xpath->query("//div[contains(@class,'chapter') and starts-with(@id,'chapter-')]") as $node) {
        $title = '';
        $titleEl = $xpath->query(".//h2|.//h3|.//span[contains(@class,'chr-text')]", $node)->item(0);
        if ($titleEl) {
            $title = trim(preg_replace('/\s+/', ' ', $titleEl->textContent));
            $title = preg_replace('/^(Chapter\s+\d+\s*[:\-—|]\s*)(Chapter\s+\d+\s*[:\-—|])/i', '$2', $title);
            $title = trim($title);
        }
        $contentNode = $xpath->query(".//*[contains(@class,'chr-c')]|.//*[@id='chr-content']", $node)->item(0) ?: $node;
        remove_nodes_by_xpath($xpath, ".//*[contains(@class,'nav')]|.//a[contains(@class,'novel')]", $node);
        $clean = clean_fragment_html(inner_html($contentNode), $baseUrl);
        $chapters[] = ['name' => $title ?: 'Chapter', 'url' => $baseUrl, 'content' => $clean];
    }
    return $chapters;
}

/**
 * Parse novel main page, returning metadata + chapter list (name,url).
 */
function parse_novel_page(string $url, float $throttle = 1.0): array {
    eprint("Fetching novel page: $url");
    $html = http_get($url);
    [$doc, $xpath] = load_dom($html);
    $novel = [
        'url' => $url,
        'title' => '',
        'author' => '',
        'summary' => '',
        'cover' => '',
        'status' => '',
        'genre' => '',
        'chapters' => []
    ];
    $img = $xpath->query("//div[contains(@class,'book')]//img")->item(0);
    if ($img) {
        $novel['title'] = trim($img->getAttribute('alt') ?: '');
        $novel['cover'] = $img->getAttribute('src') ?: '';
    } else {
        $h1 = $xpath->query("//h1[contains(@class,'novel-title')]|//h1")->item(0);
        $novel['title'] = $h1 ? trim($h1->textContent) : '';
    }
    $summary = $xpath->query("//div[contains(@class,'desc-text')]|//div[contains(@class,'novel-summary')]")->item(0);
    $novel['summary'] = $summary ? trim($summary->textContent) : '';
    foreach ($xpath->query("//ul[contains(@class,'info')]/li") as $li) {
        $label = '';
        foreach ($li->childNodes as $c) {
            if ($c->nodeName === 'h3') { $label = trim($c->textContent); break; }
        }
        $text = trim($li->textContent);
        $value = trim(str_replace($label, '', $text));
        $cleanValue = preg_replace('/^[,\s:–—]+|[,\s:–—]+$/', '', $value);
        if (str_contains($label, 'Author')) $novel['author'] = $cleanValue;
        if (str_contains($label, 'Status')) $novel['status'] = $cleanValue;
        if (str_contains($label, 'Genre')) $novel['genre'] = $cleanValue;
    }

    $embedded = extract_embedded_chapters($html, $url);
    if (!empty($embedded)) {
        eprint("Using embedded chapters (" . count($embedded) . ")");
        $novel['chapters'] = $embedded;
        return $novel;
    }

    $rating = $xpath->query("//*[@id='rating']")->item(0);
    $novelId = $rating ? $rating->getAttribute('data-novel-id') : null;
    if ($novelId) {
        eprint("Fetching chapters via AJAX (novelId: $novelId)");
        try {
            throttle($throttle);
            $ajaxHtml = http_get(BASE_URL . '/ajax/chapter-archive?novelId=' . urlencode($novelId), ['X-Requested-With: XMLHttpRequest']);
            [$aDoc, $aXpath] = load_dom($ajaxHtml);
            foreach ($aXpath->query("//ul[contains(@class,'list-chapter')]/li") as $li) {
                $a = $aXpath->query(".//a", $li)->item(0);
                $span = $aXpath->query(".//span", $li)->item(0);
                if (!$a) continue;
                $href = $a->getAttribute('href');
                if (!preg_match('#^https?://#i', $href)) $href = rtrim(BASE_URL, '/') . '/' . ltrim($href, '/');
                $name = $span ? trim($span->textContent) : trim($a->textContent);
                $novel['chapters'][] = ['name' => $name, 'url' => $href];
            }
        } catch (Exception $e) {
            eprint("AJAX fallback failed: " . $e->getMessage());
        }
    }

    if (empty($novel['chapters'])) {
        eprint("Scraping chapter links from page...");
        $selectors = [
            "//div[contains(@class,'list-chapter')]//a",
            "//ul[contains(@class,'chapter-list')]//a",
            "//a[contains(@href,'/chapter')]"
        ];
        $seen = [];
        foreach ($selectors as $sel) {
            foreach ($xpath->query($sel) as $a) {
                $href = trim($a->getAttribute('href'));
                $text = trim($a->textContent);
                if (!$href || !$text) continue;
                if (!preg_match('#^https?://#i', $href)) $href = rtrim(BASE_URL, '/') . '/' . ltrim($href, '/');
                if (isset($seen[$href])) continue;
                $seen[$href] = true;
                $novel['chapters'][] = ['name' => $text, 'url' => $href];
            }
            if (!empty($novel['chapters'])) break;
        }
    }

    return $novel;
}

/* --------------------------- HTML builder --------------------------- */

/**
 * Build a complete A5 HTML document for the provided $novel array.
 * $novel['chapters'] should be an array of ['name','content'] entries.
 */
function build_a5_html(array $novel): string {
    $title = htmlspecialchars($novel['title'] ?: 'Untitled Novel', ENT_QUOTES | ENT_HTML5);
    $author = htmlspecialchars($novel['author'] ?? '', ENT_QUOTES | ENT_HTML5);
    $summary = nl2br(htmlspecialchars($novel['summary'] ?? '', ENT_QUOTES | ENT_HTML5));
    $html = "<!doctype html>\n<html lang='en'>\n<head>\n<meta charset='utf-8'>\n<meta name='viewport' content='width=device-width,initial-scale=1'>\n<title>{$title}</title>\n<style>" . A5_CSS . "</style>\n</head>\n<body>\n<div class='book'>\n<header>\n  <h1>{$title}</h1>\n  <h2 class='author'>{$author}</h2>\n  <div class='summary'>{$summary}</div>\n  <hr class='sep'>\n</header>\n";
    foreach ($novel['chapters'] as $i => $ch) {
        $displayTitle = trim($ch['name'] ?? '');
        if ($displayTitle === '') $displayTitle = 'Chapter ' . ($i + 1);
        $safeTitle = htmlspecialchars($displayTitle, ENT_QUOTES | ENT_HTML5);
        $content = $ch['content'] ?? '<p><em>(no content)</em></p>';
        $content = preg_replace('#<a[^>]*>(?:Prev|Next|Comments?|Report|Home|Novel|Table of Contents?)</a>#is', '', $content);
        $html .= "<article class='chapter' id='ch-" . ($i + 1) . "'>\n";
        $html .= "  <h3 class='chapter-title'>{$safeTitle}</h3>\n";
        $html .= "  <div class='chapter-content'>{$content}</div>\n</article>\n<hr class='sep'>\n";
    }
    $html .= "<footer>Archived with NovelBin Scraper • " . date('Y-m-d') . "</footer>\n</div>\n</body>\n</html>\n";
    return $html;
}

/* --------------------------- CLI / MAIN --------------------------- */

function show_help(): void {
    $name = basename(__FILE__);
    fwrite(STDOUT, <<<TXT
NovelBin Scraper — Perfect A5 Novel Archiver (Split into parts)

Usage:
  php $name --url <URL> [--out <name>] [--start N] [--end N] [--throttle SEC] [--download] [--group-size N] [--help]

Options:
  --url         Novel main page URL
  --out         Output name for folder/filename (sanitized). If omitted uses novel title.
  --start       First chapter (1-based)
  --end         Last chapter (1-based)
  --throttle    Delay between requests in seconds (default: 1.0)
  --download    Save to ~/storage/shared/Download if available (Termux)
  --group-size  Number of chapters per part (default: 100)
  --help        Show this help

TXT
    );
    exit(0);
}

$options = getopt('', ['url::', 'out::', 'start::', 'end::', 'throttle::', 'download::', 'group-size::', 'help::']);
if (isset($options['help'])) show_help();

$url = $options['url'] ?? null;
$throttle = floatval($options['throttle'] ?? 1.0);
$out = $options['out'] ?? null;
$start = isset($options['start']) ? (int)$options['start'] : null;
$end = isset($options['end']) ? (int)$options['end'] : null;
$download = !empty($options['download']);
$groupSize = isset($options['group-size']) ? max(1, (int)$options['group-size']) : 100;

if (!$url) {
    eprint("=== NovelBin Scraper — Perfect A5 Archiver ===");
    echo "Novel URL: "; $url = trim(fgets(STDIN));
    if (!$url) exit(1);
    echo "Throttle (s) [1.0]: "; $t = trim(fgets(STDIN)); if ($t !== '') $throttle = (float)$t;
    echo "Output name [auto]: "; $o = trim(fgets(STDIN)); if ($o !== '') $out = $o;
    echo "Start chapter [1]: "; $s = trim(fgets(STDIN)); if ($s !== '') $start = (int)$s;
    echo "End chapter [last]: "; $e = trim(fgets(STDIN)); if ($e !== '') $end = (int)$e;
    echo "Save to Downloads? (y/N): "; $d = trim(fgets(STDIN)); if (in_array(strtolower($d), ['y', 'yes'])) $download = true;
    echo "Group size (chapters per file) [100]: "; $g = trim(fgets(STDIN)); if ($g !== '') $groupSize = max(1, (int)$g);
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host || !in_array(strtolower($host), ALLOWED_HOSTS, true)) {
    eprint("Error: Unsupported host '$host'. Only NovelBin domains allowed.");
    exit(1);
}

try {
    $novel = parse_novel_page($url, $throttle);
    if (empty($novel['chapters'])) throw new RuntimeException('No chapters found.');

    $total = count($novel['chapters']);
    $startIdx = max(0, ($start ?: 1) - 1);
    $endIdx = min($total - 1, ($end ?: $total) - 1);
    if ($endIdx < $startIdx) $endIdx = $startIdx;

    eprint("Fetching chapters " . ($startIdx + 1) . " to " . ($endIdx + 1) . " (throttle: {$throttle}s)");

    for ($i = $startIdx; $i <= $endIdx; $i++) {
        $ch = $novel['chapters'][$i];
        if (!isset($ch['content']) || trim($ch['content']) === '') {
            $name = $ch['name'] ?: "Chapter " . ($i + 1);
            eprint("[" . ($i + 1) . "] $name");
            $res = fetch_chapter_content($ch['url'], $throttle);
            if (!empty($res['title'])) {
                $novel['chapters'][$i]['name'] = $res['title'];
            }
            $novel['chapters'][$i]['content'] = $res['content'] ?: '<p><em>(empty chapter)</em></p>';
            throttle(0.2);
        }
    }

    // Trim to requested range
    $novel['chapters'] = array_slice($novel['chapters'], $startIdx, $endIdx - $startIdx + 1);

    // Ensure sequential chapter numbering in names if not present
    $startChapter = $start ?: 1;
    for ($i = 0; $i < count($novel['chapters']); $i++) {
        $chapterNumber = $startChapter + $i;
        if (!preg_match('/Chapter\s+\d+/i', $novel['chapters'][$i]['name'])) {
            $novel['chapters'][$i]['name'] = 'Chapter ' . $chapterNumber;
        }
    }

    // Determine base directory
    $baseDir = getcwd();
    $shared = getenv('HOME') . '/storage/shared/Download';
    if ($download && is_dir($shared) && is_writable($shared)) $baseDir = $shared;

    // Determine folder name (prefer --out, else novel title, else 'novel')
    $folderName = $out ? sanitize_filename($out) : sanitize_filename($novel['title'] ?: 'novel');
    $novelDir = rtrim($baseDir, '/') . '/' . $folderName;
    if (!is_dir($novelDir)) {
        if (!mkdir($novelDir, 0777, true) && !is_dir($novelDir)) {
            throw new RuntimeException("Failed to create directory: $novelDir");
        }
    }

    // Split into groups
    $chunks = array_chunk($novel['chapters'], $groupSize);
    $totalGroups = count($chunks);
    $chapterOffset = $startChapter;

    foreach ($chunks as $idx => $group) {
        $groupStart = $chapterOffset + $idx * $groupSize;
        $groupEnd = $groupStart + count($group) - 1;

        $partNovel = $novel;
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
