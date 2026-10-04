<?php
declare(strict_types=1);

require __DIR__ . '/../src/Fetcher.php';
require __DIR__ . '/../src/Page.php';
require __DIR__ . '/../src/Handlers.php';
require __DIR__ . '/../src/Audit.php';

use SeoLoop\FetchError;
use SeoLoop\Fetcher;
use SeoLoop\Handlers;

const CACHE_DIR = '/var/cache/seoloop-api';
const RESERVED = ['json', 'field', 'min', 'color', 'format'];

Fetcher::$deadline = microtime(true) + 25;

function respond(int $code, string $text, array $data, bool $json, array $extra = []): never
{
    http_response_code($code);
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    foreach ($extra as $k => $v) {
        header("$k: $v");
    }
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $text, "\n";
    }
    exit;
}

function fail(int $code, string $msg, bool $json): never
{
    respond($code, "error: $msg", ['error' => $msg, 'status' => $code], $json);
}

function colorize(string $text): string
{
    $text = preg_replace('/^(\s+)PASS/m', "$1\e[32mPASS\e[0m", $text);
    $text = preg_replace('/^(\s+)FAIL/m', "$1\e[31mFAIL\e[0m", $text);
    return preg_replace('/^([A-Z]{3,})$/m', "\e[1m$1\e[0m", $text);
}

// parse_url() returns false for paths like /status/example.com:8080, so split by hand.
$uri = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
$path = ltrim(rawurldecode($uri), '/');
$wantsJson = false;
if (str_starts_with($path, 'v1/')) {
    $wantsJson = true;
    $path = substr($path, 3);
}
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (isset($_GET['json']) || ($_GET['format'] ?? '') === 'json'
    || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'))) {
    $wantsJson = true;
}

[$endpoint, $target] = array_pad(explode('/', $path, 2), 2, '');
$endpoint = strtolower($endpoint);

// --- static / meta routes ------------------------------------------------
if ($endpoint === '' && $target === '') {
    $browser = str_contains($accept, 'text/html');
    if ($browser && !$wantsJson && is_file(__DIR__ . '/landing.html')) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        readfile(__DIR__ . '/landing.html');
        exit;
    }
    $help = file_get_contents(__DIR__ . '/../README.txt') ?: 'SEO Loop';
    respond(200, trim($help), ['name' => 'SEO Loop', 'endpoints' => Handlers::ENDPOINTS, 'docs' => 'https://github.com/mehranshahmiri/seoloop'], $wantsJson);
}
if ($endpoint === 'up') {
    respond(200, 'ok', ['status' => 'ok'], $wantsJson);
}
if ($endpoint === 'ip') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    respond(200, $ip, ['ip' => $ip], $wantsJson);
}
if ($endpoint === 'ua') {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    respond(200, $ua, ['user_agent' => $ua], $wantsJson);
}
if ($endpoint === 'robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nAllow: /\n\nSitemap: https://seoloop.in/sitemap.xml\n";
    exit;
}
if ($endpoint === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://seoloop.in/</loc></url></urlset>' . "\n";
    exit;
}
$assets = ['og.png' => 'og.png', 'logo.png' => 'logo.png', 'favicon.png' => 'favicon.png'];
if (isset($assets[$endpoint]) && $target === '') {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile(__DIR__ . '/' . $assets[$endpoint]);
    exit;
}
if ($endpoint === 'install') {
    header('Content-Type: text/x-shellscript; charset=utf-8');
    readfile(__DIR__ . '/../install.sh');
    exit;
}
if ($endpoint === 'seoloop' && $target === '') {
    header('Content-Type: text/x-shellscript; charset=utf-8');
    readfile(__DIR__ . '/../bin/seoloop');
    exit;
}

// --- endpoint routes ---------------------------------------------------------
if (!isset(Handlers::ENDPOINTS[$endpoint])) {
    fail(404, "unknown endpoint '$endpoint'. See https://seoloop.in for the list.", $wantsJson);
}
if ($target === '') {
    fail(400, "usage: /$endpoint/<domain or url>", $wantsJson);
}

// `merge_slashes` or proxies can turn https:// into https:/ — repair it.
$target = preg_replace('#^(https?):/+#i', '$1://', $target);
$extraQuery = array_diff_key($_GET, array_flip(RESERVED));
if ($extraQuery) {
    $target .= '?' . http_build_query($extraQuery);
}

try {
    $key = hash('sha256', $endpoint . '|' . Fetcher::normalize($target));
    $cacheFile = CACHE_DIR . "/$key.json";
    $ttl = $endpoint === 'audit' ? 600 : 300;
    $result = null;
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
        $result = json_decode((string) file_get_contents($cacheFile), true);
    }
    $cached = $result !== null;
    if (!$cached) {
        $result = Handlers::$endpoint($target);
        if (is_dir(CACHE_DIR) && is_writable(CACHE_DIR)) {
            file_put_contents($cacheFile, json_encode($result), LOCK_EX);
            if (random_int(1, 100) === 1) {
                foreach (glob(CACHE_DIR . '/*.json') ?: [] as $f) {
                    if (filemtime($f) < time() - 3600) {
                        @unlink($f);
                    }
                }
            }
        }
    }
} catch (FetchError $e) {
    fail($e->getCode(), $e->getMessage(), $wantsJson);
}

$text = $result['text'];
$data = $result['data'];
$headers = ['Cache-Control' => 'public, max-age=60', 'X-Cache' => $cached ? 'HIT' : 'MISS'];

if ($endpoint === 'audit') {
    $headers['X-SeoLoop-Score'] = (string) $data['score'];
    if (isset($_GET['color']) && !$wantsJson) {
        $text = colorize($text);
    }
}

if (isset($_GET['field'])) {
    $f = (string) $_GET['field'];
    if (!array_key_exists($f, $data) || is_array($data[$f])) {
        fail(400, "no scalar field '$f' on this endpoint. Fields: " . implode(', ', array_keys(array_filter($data, 'is_scalar'))), $wantsJson);
    }
    $v = $data[$f];
    respond(200, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, [$f => $v], $wantsJson, $headers);
}

$code = 200;
if ($endpoint === 'audit' && isset($_GET['min']) && ctype_digit((string) $_GET['min']) && $data['score'] < (int) $_GET['min']) {
    $code = 412; // lets CI use `curl -f` to fail a build below a threshold
}
respond($code, $text, $data, $wantsJson, $headers);
