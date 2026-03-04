#!/usr/bin/env php
<?php
/**
 * Unit tests for NovelBin Scraper utility functions.
 *
 * Run with:
 *   php tests.php
 *
 * Tests pure utility functions that do not require network access.
 */

declare(strict_types=1);

// ── Bootstrap: load functions without executing the CLI entry-point ──────────

// Capture the original argv so the script's global code (which reads argv/stdin)
// is never reached; we include only up to the function definitions.
$sourceCode = file_get_contents(__DIR__ . '/novelbin/novelbin.php');

// Strip the shebang line so eval() does not choke on it.
$sourceCode = preg_replace('/^#!.*\n/', '', $sourceCode, 1);

// Strip declare(strict_types=1) — not valid inside eval().
$sourceCode = preg_replace('/\bdeclare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', '', $sourceCode);

// Replace the CLI entry-point block (everything from "/* --- CLI / MAIN --- */"
// onwards) with a no-op so only constants and functions are defined.
$sourceCode = preg_replace(
    '/\/\*[- ]*CLI \/ MAIN[- ]*\*\/.*$/s',
    '',
    $sourceCode
);

eval('?>' . $sourceCode);

// ── Minimal test harness ──────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function ok(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "\033[32m  PASS\033[0m  $description\n";
        $passed++;
    } else {
        echo "\033[31m  FAIL\033[0m  $description\n";
        $failed++;
    }
}

function section(string $title): void {
    echo "\n\033[1m$title\033[0m\n";
}

// ── Tests ─────────────────────────────────────────────────────────────────────

section('sanitize_filename');

ok(sanitize_filename('  My Novel  ') === 'My Novel',
    'trims surrounding whitespace');

ok(sanitize_filename('Novel: The "Hero"') === 'Novel The Hero',
    'removes illegal filename characters');

ok(sanitize_filename("File\x00Name") === 'FileName',
    'strips NUL bytes');

ok(sanitize_filename('   ') === 'novel',
    'falls back to "novel" when result is empty');

ok(sanitize_filename(str_repeat('a', 300)) === str_repeat('a', 240),
    'truncates filenames longer than 240 characters');

ok(sanitize_filename('Multiple   Spaces') === 'Multiple Spaces',
    'collapses multiple spaces into one');

section('url_join');

ok(url_join('https://example.com/path/page', 'https://other.com/file') === 'https://other.com/file',
    'returns already-absolute URLs unchanged');

ok(url_join('https://example.com/path/page', '//cdn.example.com/file') === 'https://cdn.example.com/file',
    'resolves protocol-relative URLs using base scheme');

ok(url_join('https://example.com/path/page', '/abs/path') === 'https://example.com/abs/path',
    'resolves root-relative paths');

ok(url_join('https://example.com/path/to/page', 'relative.html') === 'https://example.com/path/to/relative.html',
    'resolves relative paths against base directory');

ok(str_ends_with(url_join('https://example.com/base/', 'chapter-1'), 'chapter-1'),
    'appends relative URL to base with trailing slash');

section('throttle');

$start = microtime(true);
throttle(0.05);
$elapsed = microtime(true) - $start;
ok($elapsed >= 0.04, 'throttle(0.05) sleeps at least ~50ms');
ok($elapsed < 1.0, 'throttle(0.05) does not sleep too long');

throttle(0.0);   // should be a no-op
ok(true, 'throttle(0.0) does not throw');

section('load_dom');

[$doc, $xpath] = load_dom('<html><body><p id="test">Hello</p></body></html>');
$node = $xpath->query('//*[@id="test"]')->item(0);
ok($node !== null && $node->textContent === 'Hello',
    'load_dom parses HTML and XPath query works');

section('inner_html');

[$doc2, $xpath2] = load_dom('<html><body><div id="root"><p>One</p><p>Two</p></div></body></html>');
$root = $xpath2->query('//*[@id="root"]')->item(0);
$ih = inner_html($root);
ok(str_contains($ih, '<p>One</p>') && str_contains($ih, '<p>Two</p>'),
    'inner_html returns serialized child HTML');

section('remove_nodes_by_xpath');

[$doc3, $xpath3] = load_dom('<html><body><div id="keep"><script>bad</script><p>Good</p></div></body></html>');
remove_nodes_by_xpath($xpath3, '//script');
$keep = $xpath3->query('//*[@id="keep"]')->item(0);
ok($keep !== null && !str_contains(inner_html($keep), '<script'),
    'remove_nodes_by_xpath removes matching nodes');

section('clean_fragment_html');

$frag = '<div><nav>Nav</nav><script>alert(1)</script><p class="chapter-content">Content</p></div>';
$cleaned = clean_fragment_html($frag);
ok(!str_contains($cleaned, '<script'), 'clean_fragment_html strips <script> tags');
ok(!str_contains($cleaned, '<nav'),    'clean_fragment_html strips <nav> elements');
ok(str_contains($cleaned, 'Content'), 'clean_fragment_html preserves text content');

// ── Summary ───────────────────────────────────────────────────────────────────

$total = $passed + $failed;
echo "\n" . str_repeat('─', 40) . "\n";
echo "Results: \033[32m{$passed} passed\033[0m";
if ($failed > 0) {
    echo ", \033[31m{$failed} failed\033[0m";
}
echo " / {$total} total\n";

exit($failed > 0 ? 1 : 0);
