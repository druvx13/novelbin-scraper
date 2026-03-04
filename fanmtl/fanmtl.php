<?php
/**
 * FanMTL Scraper — Readwn-style A5 Novel Archiver (Single-file)
 *
 * - Scrapes novels from fanmtl.com using selectors and TOC pagination logic
 *   inspired by WebToEpub's ReadwnParser.
 * - Outputs A5-optimized HTML files and splits into parts (default 100 chapters per file).
 *
 * Usage examples:
 *   php fanmtl.php --url "https://www.fanmtl.com/novel/..." --start 51
 *   php fanmtl.php --url "..." --preserve-numbers
 *
 * Notes:
 * - No external libraries. Uses cURL and DOMDocument.
 * - Default minimum throttle is 3.0 seconds.
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
mb_internal_encoding('UTF-8');

const ALLOWED_HOSTS = ['fanmtl.com', 'www.fanmtl.com'];
const BASE_URL = 'https://fanmtl.com';
const MINIMUM_THROTTLE = 3.0; // seconds

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
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FanMTLScraper/1.3)',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
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
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if (str_starts_with($rel, '/')) {
        return "$scheme://$host$port$rel";
    }
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($rel, '/');
    $abs = preg_replace('#(?<!:)(/\.?/)#', '/', $abs);
    while (preg_match('#/[^/]+/\.\./#', $abs)) {
        $abs = preg_replace('#/[^/]+/\.\./#', '/', $abs, 1);
    }
    return $abs;
}

/**
 * Try to extract a chapter number from a title string.
 */
function extract_chapter_number_from_title(string $title): ?int {
    if (preg_match('/(?:Chapter|Chap|Ch)[^\d]{0,4}(\d+)/i', $title, $m)) return (int)$m[1];
    if (preg_match('/^\s*(\d{1,4})[\.\)\-\s:]/', $title, $m)) return (int)$m[1];
    if (preg_match('/\b([1-9][0-9]{0,3})\b/', $title, $m)) {
        $n = (int)$m[1];
        if ($n <= 10000) return $n;
    }
    return null;
}

/**
 * Strip a leading numeric/chapter prefix from a title.
 */
function strip_leading_chapter_prefix(string $title): string {
    $t = preg_replace('/^\s*(?:Chapter|Chap|Ch)[\s\.\-:]*\d+[\s\.\-:]*\s*/i', '', $title);
    $t = preg_replace('/^\s*\d{1,4}[\.\)\-:\s]+\s*/', '', $t);
    return trim($t);
}

/**
 * Clean fragment HTML (keeps href/src/alt/title; resolves relative URLs).
 */
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

/* --------------------------- Readwn-style TOC helpers (improved) --------------------------- */

function extract_partial_chapter_list_from_dom(DOMXPath $xpath, string $baseUrl): array {
    $items = [];
    $selectors = [
        "//ul[contains(@class,'chapter-list')]//a",
        "//div[contains(@class,'chapter-list')]//a",
        "//div[contains(@class,'list-chapter')]//a",
        "//ul[contains(@class,'list-chapter')]//a",
        "//a[contains(@class,'chapter-link')]",
    ];
    foreach ($selectors as $sel) {
        $nodes = $xpath->query($sel);
        if (!$nodes || $nodes->length === 0) continue;
        foreach ($nodes as $link) {
            $href = trim($link->getAttribute('href'));
            $href = $href ? url_join($baseUrl, $href) : $baseUrl;
            $num = '';
            $titleText = '';
            $numNode = $xpath->query(".//*[contains(@class,'chapter-no')]", $link);
            if ($numNode && $numNode->length) $num = trim(preg_replace('/\s+/', ' ', $numNode->item(0)->textContent));
            $titleNode = $xpath->query(".//*[contains(@class,'chapter-title')]", $link);
            if ($titleNode && $titleNode->length) $titleText = trim(preg_replace('/\s+/', ' ', $titleNode->item(0)->textContent));
            $title = $titleText;
            if ($num && $titleText) {
                if (strpos($titleText, $num) === false) $title = $num . ': ' . $titleText;
            } elseif ($num && !$titleText) {
                $title = $num;
            } elseif (!$num && $titleText) {
                $title = $titleText;
            } else {
                $title = trim($link->textContent) ?: 'Chapter';
            }
            $items[] = ['name' => $title, 'url' => $href];
        }
        if (!empty($items)) break;
    }
    return $items;
}

/**
 * Find all TOC page URLs. Detects both query (?page=) and path (/page/N/) pagination.
 */
function get_urls_of_toc_pages_from_dom(DOMXPath $xpath, string $baseUrl): array {
    $urls = [];
    $nodes = $xpath->query("//ul[contains(@class,'pagination')]//a | //a[contains(@class,'page-numbers')] | //nav[contains(@class,'pagination')]//a | //a[contains(@class,'page-link')]");
    $anchors = [];
    if ($nodes && $nodes->length) {
        foreach ($nodes as $n) {
            $href = trim($n->getAttribute('href'));
            if ($href === '') continue;
            $anchors[] = url_join($baseUrl, $href);
        }
    }
    $pageNums = [];
    $patternInfo = null;
    foreach ($anchors as $a) {
        if (preg_match('/[?&]page=(\d+)/', $a, $m)) {
            $pageNums[] = (int)$m[1];
            $patternInfo = ['type' => 'query', 'param' => 'page', 'example' => $a];
            continue;
        }
        if (preg_match('#/page/(\d+)/?#i', $a, $m)) {
            $pageNums[] = (int)$m[1];
            $patternInfo = ['type' => 'path', 'regex' => '#/page/{n}/#', 'example' => $a];
            continue;
        }
        if (preg_match('#/paged/(\d+)/?#i', $a, $m)) {
            $pageNums[] = (int)$m[1];
            $patternInfo = ['type' => 'path', 'regex' => '#/paged/{n}/#', 'example' => $a];
            continue;
        }
    }
    if (!empty($pageNums)) {
        $maxPage = max($pageNums);
        if ($patternInfo && $patternInfo['type'] === 'query') {
            $example = $patternInfo['example'];
            $p = parse_url($example);
            parse_str($p['query'] ?? '', $qp);
            $qp['page'] = 1;
            $p['query'] = http_build_query($qp);
            $baseExample = (isset($p['scheme']) ? $p['scheme'] . '://' : '') . ($p['host'] ?? '') . ($p['path'] ?? '') . '?' . $p['query'];
            $u = new URLWrapper($baseExample);
            for ($i = 1; $i <= $maxPage; $i++) {
                $c = clone $u;
                $c->setQueryParam('page', (string)$i);
                $urls[] = $c->href();
            }
            return $urls;
        } elseif ($patternInfo && $patternInfo['type'] === 'path') {
            $ex = $patternInfo['example'];
            $repl = preg_replace('#/page/\d+/?$#i', '/page/%d/', $ex);
            $repl = preg_replace('#/paged/\d+/?$#i', '/page/%d/', $repl);
            for ($i = 1; $i <= $maxPage; $i++) {
                $urls[] = str_replace('%d', (string)$i, $repl);
            }
            return $urls;
        } else {
            $example = $anchors[0] ?? '';
            if ($example && preg_match('/(.*?)(\d+)(\/?)(\?.*)?$/', $example, $m)) {
                $prefix = $m[1];
                $suffix = isset($m[3]) ? $m[3] : '';
                for ($i = 1; $i <= $maxPage; $i++) {
                    $urls[] = $prefix . $i . $suffix;
                }
                return $urls;
            }
        }
    }
    $nextNodes = $xpath->query("//a[@rel='next' or contains(translate(@class,'NEXT','next'),'next') or contains(translate(@class,'OLDER','older'),'older')]");
    if ($nextNodes && $nextNodes->length) {
        $found = [];
        $current = $baseUrl;
        $limit = 200;
        for ($i = 0; $i < $limit; $i++) {
            if (isset($found[$current])) break;
            $found[$current] = true;
            $urls[] = $current;
            try {
                throttle(0.2);
                $html = http_get($current);
                [$dDoc, $dXpath] = load_dom($html);
                $next = $dXpath->query("//a[@rel='next' or contains(translate(@class,'NEXT','next'),'next') or contains(translate(@class,'OLDER','older'),'older')]");
                if (!$next || $next->length === 0) break;
                $href = trim($next->item(0)->getAttribute('href'));
                if (!$href) break;
                $current = url_join($current, $href);
            } catch (Throwable $e) {
                break;
            }
        }
        return array_values(array_unique($urls));
    }
    return [$baseUrl];
}

/* URL wrapper class to manipulate query parameters easily */
class URLWrapper {
    private array $parts;
    private string $scheme;
    private string $host;
    private ?int $port;
    private string $path;
    private array $queryParams = [];
    private string $fragment = '';

    public function __construct(string $url) {
        $this->parts = parse_url($url);
        $this->scheme = $this->parts['scheme'] ?? 'https';
        $this->host = $this->parts['host'] ?? '';
        $this->port = $this->parts['port'] ?? null;
        $this->path = $this->parts['path'] ?? '/';
        if (isset($this->parts['query'])) {
            parse_str($this->parts['query'], $this->queryParams);
        }
        $this->fragment = $this->parts['fragment'] ?? '';
    }

    public function hasQueryParam(string $k): bool {
        return array_key_exists($k, $this->queryParams);
    }

    public function getQueryParam(string $k): ?string {
        return $this->queryParams[$k] ?? null;
    }

    public function setQueryParam(string $k, string $v): void {
        $this->queryParams[$k] = $v;
    }

    public function href(): string {
        $port = $this->port ? ':' . $this->port : '';
        $q = !empty($this->queryParams) ? '?' . http_build_query($this->queryParams) : '';
        $f = $this->fragment ? '#' . $this->fragment : '';
        return "{$this->scheme}://{$this->host}{$port}{$this->path}{$q}{$f}";
    }

    public function __clone() {
        // nothing special
    }
}

/* --------------------------- Chapter fetching (improved) --------------------------- */

/**
 * Fetch and extract a single chapter's title and content.
 * Improvements:
 *  - Prefer titles inside the candidate content node (h2/h3/strong.chapter-title etc).
 *  - Avoid global //h1 fallback (novel title).
 *  - Try multiple content selectors common to FanMTL.
 */
function fetch_chapter_content(string $url, float $throttle = MINIMUM_THROTTLE): array {
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
    throttle($throttle);
    $html = http_get($url);
    [$doc, $xpath] = load_dom($html);

    // Better candidate list covering fanmtl chapter pages
    $candidates = [
        "//*[@id='chapter-content']",
        "//*[contains(@class,'chapter-content')]",
        "//*[contains(@class,'read-content')]",
        "//*[contains(@class,'entry-content')]",
        "//*[contains(@class,'j_readContent')]",
        "//*[contains(@class,'content') and contains(./ancestor::div[1]/@class,'read')]",
        "//article", "//main", "//*[@id='content']"
    ];

    $bestHtml = '';
    $bestScore = 0;
    $foundTitle = '';
    $matchedSelector = '';

    foreach ($candidates as $xp) {
        $nodes = @$xpath->query($xp);
        if (!$nodes || $nodes->length === 0) continue;
        foreach ($nodes as $node) {
            // Remove obvious junk inside candidate
            remove_nodes_by_xpath($xpath, ".//form|.//button|.//input|.//textarea|.//*[contains(@class,'comment')]|.//*[contains(@class,'share')]|.//*[contains(@class,'adsbox')]", $node);

            // 1) Prefer title inside the node only (most reliable)
            $titleNode = null;
            $titleSelectors = [
                ".//*[contains(@class,'chapter-title')]",
                ".//h2[contains(@class,'chapter-title')]",
                ".//strong[contains(@class,'chapter-title')]",
                ".//h2", ".//h3", ".//h1", ".//strong"
            ];
            foreach ($titleSelectors as $tq) {
                $tqNodes = $xpath->query($tq, $node);
                if ($tqNodes && $tqNodes->length) { $titleNode = $tqNodes->item(0); break; }
            }

            // 2) If not found inside node, try some page-level chapter title selectors that are likely to be chapter titles (but avoid global h1)
            if (!$titleNode) {
                $fallbackSelectors = [
                    "//h2[contains(@class,'chapter-title')]",
                    "//strong[contains(@class,'chapter-title')]",
                    "//h2[contains(@class,'title') and contains(translate(.,'CHAPTER','chapter'),'chapter')]",
                    "//div[contains(@class,'post-title')]//h2",
                ];
                foreach ($fallbackSelectors as $fs) {
                    $fsNodes = $xpath->query($fs);
                    if ($fsNodes && $fsNodes->length) { $titleNode = $fsNodes->item(0); break; }
                }
            }

            if ($titleNode) {
                $foundTitle = trim(preg_replace('/\s+/', ' ', $titleNode->textContent));
                // If this found title looks exactly like the novel title (page-level h1), prefer leaving blank so we don't overwrite
                $h1Candidate = $xpath->query("//h1");
                if ($h1Candidate && $h1Candidate->length) {
                    $pageH1 = trim(preg_replace('/\s+/', ' ', $h1Candidate->item(0)->textContent));
                    if (mb_strlen($foundTitle) === mb_strlen($pageH1) && stripos($pageH1, $foundTitle) !== false) {
                        // foundTitle appears to be the page H1 -> ignore it (avoid novel title)
                        $foundTitle = '';
                    }
                }
                // Remove title element if it was inside the content node to avoid duplication
                if ($titleNode->parentNode && ($node->isSameNode($titleNode->parentNode) || $node->contains($titleNode))) {
                    try { $titleNode->parentNode->removeChild($titleNode); } catch (Throwable $e) {}
                }
            }

            // Score candidate by text length and paragraph count
            $text = trim($node->textContent);
            $pCount = $xpath->query('.//p', $node)->length;
            $score = mb_strlen($text) + $pCount * 400;
            if (($text !== '' && mb_strlen($text) > 60) && $score > $bestScore) {
                $bestScore = $score;
                $bestHtml = inner_html($node);
                $matchedSelector = $xp;
            }
        }
        if ($bestScore > 0) break;
    }

    // Fallback: try some additional selectors targeting FanMTL chapter pages specifically
    if ($bestScore === 0) {
        $extra = [
            "//*[contains(@class,'j_readContent')]",
            "//*[contains(@class,'read-body')]",
            "//*[contains(@class,'reader-content')]"
        ];
        foreach ($extra as $xp) {
            $nodes = @$xpath->query($xp);
            if ($nodes && $nodes->length) {
                foreach ($nodes as $node) {
                    $text = trim($node->textContent);
                    $pCount = $xpath->query('.//p', $node)->length;
                    $score = mb_strlen($text) + $pCount * 300;
                    if ($text !== '' && $score > $bestScore) {
                        $bestScore = $score;
                        $bestHtml = inner_html($node);
                        $matchedSelector = $xp;
                    }
                }
            }
            if ($bestScore > 0) break;
        }
    }

    // Very last resort: use body (but avoid global H1 as title)
    if ($bestScore === 0) {
        $body = $xpath->query('//body')->item(0);
        if ($body) {
            remove_nodes_by_xpath($xpath, ".//script|.//style|.//nav|.//footer", $body);
            $bestHtml = inner_html($body);
            $matchedSelector = 'body (fallback)';
        }
    }

    $clean = clean_fragment_html($bestHtml, $url);

    return ['title' => $foundTitle, 'content' => $clean];
}

/* --------------------------- Novel page parsing --------------------------- */

function extract_embedded_chapters(string $html, string $baseUrl): array {
    [$doc, $xpath] = load_dom($html);
    $chapters = [];
    foreach ($xpath->query("//div[contains(@class,'chapter') and (contains(@id,'chapter') or contains(@class,'chapter-'))]") as $node) {
        $title = '';
        $titleEl = $xpath->query(".//h2|.//h3|.//h4|.//strong[contains(@class,'title')]|.//div[contains(@class,'title')]", $node)->item(0);
        if ($titleEl) {
            $title = trim(preg_replace('/\s+/', ' ', $titleEl->textContent));
            $title = preg_replace('/^(Chapter\s+\d+\s*[:\-—|]\s*)/i', '', $title);
            $title = trim($title);
        }
        $contentNode = $xpath->query(".//*[contains(@class,'content')]|.//*[contains(@class,'chapter-content')]", $node)->item(0) ?: $node;
        remove_nodes_by_xpath($xpath, ".//*[contains(@class,'nav')]|.//a[contains(@class,'novel')]", $node);
        $clean = clean_fragment_html(inner_html($contentNode), $baseUrl);
        $chapters[] = ['name' => $title ?: 'Chapter', 'url' => $baseUrl, 'content' => $clean];
    }
    return $chapters;
}

/**
 * Parse main novel page to get metadata and chapter list.
 * IMPORTANT: extract chapters from the initial DOM first, then fetch TOC pages (AJAX),
 * so we don't miss the main-page block (chapters 1..100) which is present in HTML.
 */
function parse_novel_page(string $url, float $throttle = MINIMUM_THROTTLE): array {
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
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

    $h1 = $xpath->query("//h1[itemprop='name' or contains(@class,'novel-title')]|//h1")->item(0);
    if ($h1) $novel['title'] = trim(preg_replace('/\s+/', ' ', $h1->textContent));

    $img = $xpath->query("//figure[contains(@class,'cover')]//img|//img[contains(@class,'cover')]")->item(0);
    if ($img) {
        $novel['cover'] = $img->getAttribute('src') ?: '';
        if ($novel['title'] === '') $novel['title'] = trim($img->getAttribute('alt') ?: '');
    }

    $authorNode = $xpath->query("//span[@itemprop='author']")->item(0);
    if ($authorNode) $novel['author'] = trim($authorNode->textContent);

    $summaryNode = $xpath->query("//div[contains(@class,'summary')]//div[contains(@class,'content')] | //div[contains(@class,'summary')]|//div[contains(@class,'desc')]")->item(0);
    $novel['summary'] = $summaryNode ? trim($summaryNode->textContent) : '';

    // 1) Extract chapters from initial main page DOM first
    $seen = [];
    $initialPartials = extract_partial_chapter_list_from_dom($xpath, $url);
    foreach ($initialPartials as $p) {
        $href = $p['url'] ?? $url;
        $text = $p['name'] ?? '';
        if (!$href || !$text) continue;
        if (isset($seen[$href])) continue;
        $seen[$href] = true;
        $novel['chapters'][] = ['name' => $text, 'url' => $href];
    }

    // 2) Get TOC pages (AJAX endpoints /e/extend/fy.php?page=... etc) and fetch them
    $tocPageUrls = get_urls_of_toc_pages_from_dom($xpath, $url);
    if (empty($tocPageUrls)) $tocPageUrls = [$url];

    foreach ($tocPageUrls as $tocUrl) {
        // skip main page (already processed)
        if ($tocUrl === $url) continue;
        try {
            throttle($throttle);
            $tocHtml = http_get($tocUrl);
            [$tDoc, $tXpath] = load_dom($tocHtml);
            $partials = extract_partial_chapter_list_from_dom($tXpath, $tocUrl);
            foreach ($partials as $p) {
                $href = $p['url'] ?? $tocUrl;
                $text = $p['name'] ?? '';
                if (!$href || !$text) continue;
                if (isset($seen[$href])) continue;
                $seen[$href] = true;
                $novel['chapters'][] = ['name' => $text, 'url' => $href];
            }
        } catch (Throwable $e) {
            eprint("Warning: failed to fetch TOC page $tocUrl (" . $e->getMessage() . ")");
        }
    }

    // Final fallback: if still empty, try searching for chapter links generally
    if (empty($novel['chapters'])) {
        eprint("Fallback: scraping chapter links from page...");
        $selectors = [
            "//div[contains(@class,'chapter-list')]//a",
            "//ul[contains(@class,'chapter-list')]//a",
            "//div[contains(@class,'list-chapter')]//a",
            "//a[contains(@href,'/chapter') or contains(@href,'/chap')]"
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

    // Normalize ordering: if numeric labels suggest descending order, reverse to oldest-first
    $firstNum = null; $lastNum = null; $nums = [];
    foreach ($novel['chapters'] as $ch) { $nums[] = extract_chapter_number_from_title($ch['name'] ?? ''); }
    foreach ($nums as $n) { if ($n !== null) { $firstNum = $n; break; } }
    for ($i = count($nums) - 1; $i >= 0; $i--) { if ($nums[$i] !== null) { $lastNum = $nums[$i]; break; } }
    if ($firstNum !== null && $lastNum !== null && $firstNum > $lastNum) {
        $novel['chapters'] = array_reverse($novel['chapters']);
    }

    return $novel;
}

/* --------------------------- HTML builder --------------------------- */

function build_a5_html(array $novel): string {
    $title = htmlspecialchars($novel['title'] ?: 'Untitled Novel', ENT_QUOTES | ENT_HTML5);
    $author = htmlspecialchars($novel['author'] ?? '', ENT_QUOTES | ENT_HTML5);
    $summary = nl2br(htmlspecialchars($novel['summary'] ?? '', ENT_QUOTES | ENT_HTML5));
    $html = "<!doctype html>\n<html lang='en'>\n<head>\n<meta charset='utf-8'>\n<meta name='viewport' content='width=device-width,initial-scale=1'>\n<title>{$title}</title>\n<style>" . A5_CSS . "</style>\n</head>\n<body>\n<div class='book'>\n<header>\n";
    $html .= "<h1>{$title}</h1>\n";
    if ($author !== '') $html .= "<h2 class='author'>{$author}</h2>\n";
    if ($summary !== '') $html .= "<div class='summary'>{$summary}</div>\n";
    $html .= "<hr class='sep'>\n</header>\n";

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

    $html .= "<footer>Archived with FanMTL Scraper • " . date('Y-m-d') . "</footer>\n</div>\n</body>\n</html>\n";
    return $html;
}

/* --------------------------- CLI / MAIN --------------------------- */

function show_help(): void {
    $name = basename(__FILE__);
    fwrite(STDOUT, <<<TXT
FanMTL Scraper — Readwn-style A5 Novel Archiver (Single-file)

Usage:
  php $name --url <URL> [--out <name>] [--start N] [--end N] [--throttle SEC] [--download] [--group-size N] [--preserve-numbers] [--help]

Options:
  --url         Novel main page URL (fanmtl.com)
  --out         Output name for folder/filename (sanitized). If omitted uses novel title.
  --start       First chapter (1-based)
  --end         Last chapter (1-based)
  --throttle    Delay between requests in seconds (default: 3.0, minimum enforced)
  --download    Save to ~/storage/shared/Download if available (Termux)
  --group-size  Number of chapters per part (default: 100)
  --preserve-numbers  Keep original site chapter titles (no renumbering)
  --help        Show this help

TXT
    );
    exit(0);
}

$options = getopt('', ['url:', 'out:', 'start:', 'end:', 'throttle:', 'download', 'group-size:', 'preserve-numbers', 'help']);
if (isset($options['help'])) show_help();

$url = $options['url'] ?? null;
$throttle = floatval($options['throttle'] ?? MINIMUM_THROTTLE);
if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
$out = $options['out'] ?? null;
$start = isset($options['start']) ? (int)$options['start'] : null;
$end = isset($options['end']) ? (int)$options['end'] : null;
$download = !empty($options['download']);
$groupSize = isset($options['group-size']) ? max(1, (int)$options['group-size']) : 100;
$PRESERVE_NUMBERS = isset($options['preserve-numbers']) || $PRESERVE_NUMBERS;

if (!$url) {
    eprint("=== FanMTL Scraper — A5 Archiver ===");
    echo "Novel URL: "; $url = trim(fgets(STDIN));
    if (!$url) exit(1);
    echo "Throttle (s) [3.0]: "; $t = trim(fgets(STDIN)); if ($t !== '') $throttle = (float)$t;
    if ($throttle < MINIMUM_THROTTLE) $throttle = MINIMUM_THROTTLE;
    echo "Output name [auto]: "; $o = trim(fgets(STDIN)); if ($o !== '') $out = $o;
    echo "Start chapter [1]: "; $s = trim(fgets(STDIN)); if ($s !== '') $start = (int)$s;
    echo "End chapter [last]: "; $e = trim(fgets(STDIN)); if ($e !== '') $end = (int)$e;
    echo "Save to Downloads? (y/N): "; $d = trim(fgets(STDIN)); if (in_array(strtolower($d), ['y', 'yes'])) $download = true;
    echo "Group size (chapters per file) [100]: "; $g = trim(fgets(STDIN)); if ($g !== '') $groupSize = max(1, (int)$g);
    echo "Preserve site numbering? (y/N): "; $p = trim(fgets(STDIN)); if (in_array(strtolower($p), ['y','yes'])) $PRESERVE_NUMBERS = true;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host || !in_array(strtolower($host), ALLOWED_HOSTS, true)) {
    eprint("Error: Unsupported host '$host'. Only fanmtl.com allowed.");
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

    // Ensure sequential chapter numbering in names (normalized numbering starting at --start or 1)
    if (!$PRESERVE_NUMBERS) {
        $startChapter = $start ?: 1;
        $chapterCount = count($novel['chapters']);
        for ($i = 0; $i < $chapterCount; $i++) {
            $chapterNumber = $startChapter + $i;
            $origName = $novel['chapters'][$i]['name'] ?? '';
            $short = strip_leading_chapter_prefix($origName);
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
    $chapterOffset = $start ?: 1;

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
