<?php
declare(strict_types=1);

require __DIR__ . '/../src/Fetcher.php';
require __DIR__ . '/../src/Page.php';
require __DIR__ . '/../src/Handlers.php';
require __DIR__ . '/../src/Audit.php';
require __DIR__ . '/../src/Runner.php';
require __DIR__ . '/../src/Mcp.php';

use SeoLoop\FetchError;
use SeoLoop\Fetcher;
use SeoLoop\Handlers;

define('CACHE_DIR', getenv('SEOLOOP_CACHE_DIR') ?: '/var/cache/seoloop-api');
define('BASE_URL', rtrim(getenv('SEOLOOP_BASE_URL') ?: 'https://seoloop.in', '/'));
const RESERVED = ['json', 'field', 'min', 'color', 'format', 'only', 'skip', 'vs'];
SeoLoop\Runner::$dir = CACHE_DIR;

Fetcher::$deadline = microtime(true) + 25;

function respond(int $code, string $text, array $data, bool $json, array $extra = []): never
{
    http_response_code($code);
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    foreach ($extra as $k => $v) {
        header("$k: $v");
    }
    if (isset($extra['Content-Type']) && !$json) {
        echo $text, "\n";
        exit;
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
if ($endpoint === 'mcp') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version');
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($m === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($m !== 'POST') {
        header('Allow: POST, OPTIONS');
        fail(405, 'MCP endpoint: POST JSON-RPC here. See https://github.com/mehranshahmiri/seoloop#mcp-server', true);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $msg = json_decode((string) $raw, true);
    header('Content-Type: application/json; charset=utf-8');
    if ($msg === null) {
        http_response_code(400);
        echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]), "\n";
        exit;
    }
    $batch = is_array($msg) && array_is_list($msg) && $msg !== [];
    $responses = array_values(array_filter(array_map(fn($x) => SeoLoop\Mcp::handle($x), $batch ? array_slice($msg, 0, 10) : [$msg])));
    if (!$responses) {
        http_response_code(202);
        exit;
    }
    echo json_encode($batch ? $responses : $responses[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
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
    echo "User-agent: *\nAllow: /\n\nSitemap: " . BASE_URL . "/sitemap.xml\n";
    exit;
}
if ($endpoint === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . BASE_URL . '/</loc></url><url><loc>' . BASE_URL . '/docs</loc></url></urlset>' . "\n";
    exit;
}
$assets = [
    'og.png' => ['og.png', 'image/png', 86400], 'logo.png' => ['logo.png', 'image/png', 86400], 'favicon.png' => ['favicon.png', 'image/png', 86400],
    'site.css' => ['site.css', 'text/css; charset=utf-8', 300], 'docs' => ['docs.html', 'text/html; charset=utf-8', 300],
];
if (isset($assets[$endpoint]) && $target === '') {
    [$file, $type, $ttl] = $assets[$endpoint];
    header("Content-Type: $type");
    header("Cache-Control: public, max-age=$ttl");
    readfile(__DIR__ . '/' . $file);
    exit;
}
if ($endpoint === 'openapi.json') {
    $paths = [];
    foreach (Handlers::ENDPOINTS as $name => $desc) {
        $paths["/v1/{$name}/{target}"] = ['get' => [
            'summary' => $desc, 'operationId' => $name,
            'parameters' => array_merge(
                [
                    ['name' => 'target', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Domain or URL, e.g. example.com'],
                    ['name' => 'field', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Return only this top-level scalar field'],
                ],
                $name === 'audit' ? [
                    ['name' => 'min', 'in' => 'query', 'schema' => ['type' => 'integer'], 'description' => 'Respond 412 if the score is below this'],
                    ['name' => 'only', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated categories or check ids to include'],
                    ['name' => 'skip', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated categories or check ids to exclude'],
                    ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['text', 'csv', 'md']]],
                ] : [],
                $name === 'compare' ? [
                    ['name' => 'vs', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Second domain or URL'],
                ] : []
            ),
            'responses' => ['200' => ['description' => 'JSON result'], '422' => ['description' => 'Invalid or blocked target'], '429' => ['description' => 'Rate limited'], '502' => ['description' => 'Target unreachable']],
        ]];
    }
    $spec = ['openapi' => '3.0.3', 'info' => ['title' => 'SEO Loop', 'version' => Handlers::VERSION, 'description' => 'SEO checks over HTTP. Free, no API key. https://github.com/mehranshahmiri/seoloop', 'license' => ['name' => 'MIT']], 'servers' => [['url' => BASE_URL]], 'paths' => $paths];
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}
if ($endpoint === 'llms.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    $lines = ['# SEO Loop', '', '> Free SEO checks over plain HTTP. No signup, no API key. Open source (MIT): https://github.com/mehranshahmiri/seoloop', '', 'Base URL: ' . BASE_URL, 'Add ?json (or use /v1/...) for JSON. OpenAPI: ' . BASE_URL . '/openapi.json', '', '## Endpoints', ''];
    foreach (Handlers::ENDPOINTS as $name => $desc) {
        $lines[] = "- {$name}: {$desc}. GET " . BASE_URL . "/{$name}/example.com";
    }
    echo implode("\n", $lines), "\n";
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
    $result = SeoLoop\Runner::run($endpoint, $target, isset($_GET['vs']) ? (string) $_GET['vs'] : null);
    $cached = $result['cached'];
} catch (FetchError $e) {
    fail($e->getCode(), $e->getMessage(), $wantsJson);
}

$text = $result['text'];
$data = $result['data'];
$headers = ['Cache-Control' => 'public, max-age=60', 'X-Cache' => $cached ? 'HIT' : 'MISS'];

if ($endpoint === 'audit') {
    $fmt = strtolower((string) ($_GET['format'] ?? 'text'));
    $csv = fn($k) => array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($_GET[$k] ?? ''))))));
    $opts = ['only' => $csv('only'), 'skip' => $csv('skip'), 'format' => $fmt];
    if ($opts['only'] || $opts['skip'] || in_array($fmt, ['csv', 'md', 'markdown'], true)) {
        $result = SeoLoop\Audit::present(['url' => $data['url'], 'checks' => $data['checks']], $opts);
        $text = $result['text'];
        $data = $result['data'];
    }
    if ($fmt === 'csv') {
        $headers['Content-Type'] = 'text/csv; charset=utf-8';
    } elseif (in_array($fmt, ['md', 'markdown'], true)) {
        $headers['Content-Type'] = 'text/markdown; charset=utf-8';
    }
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
