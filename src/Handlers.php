<?php
declare(strict_types=1);

namespace SeoLoop;

/**
 * One static method per endpoint. Each returns ['text' => string, 'data' => array]:
 * `text` is the plain-text answer a terminal wants, `data` is the full structure for JSON.
 */
final class Handlers
{
    public const ENDPOINTS = [
        'audit'     => 'Full SEO audit with score and grade',
        'indexable' => 'Can search engines index this URL? Yes/no with reasons',
        'links'     => 'Broken-link check for the links on one page',
        'schema'    => 'JSON-LD structured data: types found and missing properties',
        'status'    => 'HTTP status code after redirects',
        'title'     => 'Page <title>',
        'meta'      => 'Title, description, canonical, robots, Open Graph, headings',
        'headers'   => 'Final response headers',
        'redirects' => 'Redirect chain',
        'ttfb'      => 'Time to first byte (with DNS/connect/TLS split)',
        'ssl'       => 'TLS certificate: issuer, expiry, protocol',
        'robots'    => 'robots.txt analysis',
        'sitemap'   => 'Sitemap discovery and URL count',
    ];

    public static function status(string $url): array
    {
        $r = Fetcher::request($url, ['method' => 'HEAD']);
        return [
            'text' => (string) $r['status'],
            'data' => ['url' => $r['url'], 'final_url' => $r['final_url'], 'status' => $r['status'], 'redirects' => count($r['hops']) - 1],
        ];
    }

    public static function title(string $url): array
    {
        $r = Fetcher::request($url);
        $title = (new Page($r['body']))->title();
        return ['text' => $title !== '' ? $title : '(no title)', 'data' => ['final_url' => $r['final_url'], 'title' => $title, 'length' => mb_strlen($title)]];
    }

    public static function headers(string $url): array
    {
        $r = Fetcher::request($url, ['method' => 'HEAD']);
        return ['text' => "HTTP {$r['status']}\n" . implode("\n", $r['raw_headers']), 'data' => ['final_url' => $r['final_url'], 'status' => $r['status'], 'headers' => $r['headers']]];
    }

    public static function redirects(string $url): array
    {
        $r = Fetcher::request($url, ['method' => 'HEAD']);
        $lines = [];
        foreach ($r['hops'] as $h) {
            $lines[] = "{$h['status']} {$h['url']}" . ($h['location'] ? "  ->  {$h['location']}" : '');
        }
        return ['text' => implode("\n", $lines), 'data' => ['hops' => $r['hops'], 'final_url' => $r['final_url'], 'count' => count($r['hops']) - 1]];
    }

    public static function ttfb(string $url): array
    {
        $r = Fetcher::request($url);
        $t = $r['timing'];
        return ['text' => $t['ttfb_ms'] . 'ms', 'data' => $t + ['final_url' => $r['final_url'], 'ip' => $r['ip']]];
    }

    public static function meta(string $url): array
    {
        $r = Fetcher::request($url);
        $p = new Page($r['body']);
        $d = [
            'final_url' => $r['final_url'],
            'title' => $p->title(),
            'description' => $p->meta('description'),
            'canonical' => $p->link('canonical'),
            'robots' => $p->meta('robots'),
            'viewport' => $p->meta('viewport'),
            'lang' => $p->lang(),
            'og_title' => $p->prop('og:title'),
            'og_description' => $p->prop('og:description'),
            'og_image' => $p->prop('og:image'),
            'twitter_card' => $p->meta('twitter:card'),
            'h1' => $p->count('h1'),
            'h2' => $p->count('h2'),
            'images_missing_alt' => $p->imagesMissingAlt(),
            'words' => $p->words(),
        ];
        $lines = [];
        foreach ($d as $k => $v) {
            $lines[] = sprintf('%-19s %s', $k . ':', $v === '' ? '-' : $v);
        }
        return ['text' => implode("\n", $lines), 'data' => $d];
    }

    public static function ssl(string $url): array
    {
        $host = parse_url(Fetcher::normalize($url), PHP_URL_HOST);
        $c = Fetcher::certificate($host);
        $text = implode("\n", [
            'subject:   ' . $c['subject'],
            'issuer:    ' . $c['issuer'],
            'valid:     ' . $c['valid_from'] . ' to ' . $c['valid_to'] . " ({$c['days_left']} days left)",
            'trusted:   ' . ($c['trusted'] ? 'yes' : 'NO'),
            'protocol:  ' . ($c['protocol'] ?? '-') . ' / ' . ($c['cipher'] ?? '-'),
            'names:     ' . implode(', ', array_slice($c['san'], 0, 8)) . (count($c['san']) > 8 ? ' ...' : ''),
        ]);
        return ['text' => $text, 'data' => $c];
    }

    /** @return array{found:bool,status:int,body:string,url:string} */
    private static function fetchOriginFile(string $url, string $path): array
    {
        $p = parse_url(Fetcher::normalize($url));
        $origin = $p['scheme'] . '://' . $p['host'];
        $r = Fetcher::request($origin . $path, ['max_bytes' => 500_000]);
        $looksHtml = stripos($r['headers']['content-type'] ?? '', 'text/html') !== false;
        return ['found' => $r['status'] === 200 && !$looksHtml, 'status' => $r['status'], 'body' => $r['body'], 'url' => $r['final_url']];
    }

    public static function robots(string $url): array
    {
        $f = self::fetchOriginFile($url, '/robots.txt');
        if (!$f['found']) {
            return ['text' => "robots.txt not found (HTTP {$f['status']})", 'data' => ['found' => false, 'status' => $f['status'], 'url' => $f['url']]];
        }
        $a = self::parseRobots($f['body']);
        return ['text' => rtrim($f['body']), 'data' => ['found' => true, 'status' => 200, 'url' => $f['url']] + $a];
    }

    /** @return array{sitemaps:string[],rules:int,blocks_all:bool} */
    public static function parseRobots(string $body): array
    {
        $sitemaps = [];
        $rules = 0;
        $blocksAll = false;
        $inStar = false;
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $k = strtolower($m[1]);
            $v = trim($m[2]);
            if ($k === 'sitemap' && $v !== '') {
                $sitemaps[] = $v;
            } elseif ($k === 'user-agent') {
                $inStar = $v === '*';
            } elseif (in_array($k, ['allow', 'disallow'], true)) {
                $rules++;
                if ($inStar && $k === 'disallow' && $v === '/') {
                    $blocksAll = true;
                }
            }
        }
        return ['sitemaps' => $sitemaps, 'rules' => $rules, 'blocks_all' => $blocksAll];
    }

    public static function sitemap(string $url): array
    {
        $robots = self::fetchOriginFile($url, '/robots.txt');
        $cands = $robots['found'] ? self::parseRobots($robots['body'])['sitemaps'] : [];
        $origin = parse_url(Fetcher::normalize($url), PHP_URL_HOST);
        $cands = array_slice($cands, 0, 3);
        if (!$cands) {
            $cands = ['https://' . $origin . '/sitemap.xml'];
        }
        foreach ($cands as $c) {
            try {
                $r = Fetcher::request($c, ['max_bytes' => 1_500_000]);
            } catch (FetchError $e) {
                continue;
            }
            if ($r['status'] !== 200 || stripos($r['body'], '<loc>') === false) {
                continue;
            }
            $locs = preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $r['body']);
            $isIndex = stripos($r['body'], '<sitemapindex') !== false;
            $d = ['found' => true, 'url' => $r['final_url'], 'type' => $isIndex ? 'index' : 'urlset', 'entries' => $locs, 'truncated' => $r['truncated'], 'declared_in_robots' => $robots['found'] && in_array($c, $cands, true) && $robots['found']];
            return ['text' => "{$r['final_url']}\n" . $locs . ($isIndex ? ' sitemaps' : ' urls') . ($r['truncated'] ? '+ (truncated)' : ''), 'data' => $d];
        }
        return ['text' => 'no sitemap found', 'data' => ['found' => false, 'tried' => $cands]];
    }

    /** Does robots.txt (the `*` group) allow $path? Longest matching rule wins; Allow beats Disallow on ties. */
    public static function robotsAllows(string $body, string $path): bool
    {
        $rules = [];
        $inStar = false;
        $seenAgentLine = false;
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }
            $k = strtolower($m[1]);
            $v = trim($m[2]);
            if ($k === 'user-agent') {
                $inStar = ($v === '*') || ($inStar && $seenAgentLine);
                $seenAgentLine = true;
                if ($v !== '*') {
                    $inStar = false;
                }
                continue;
            }
            $seenAgentLine = false;
            if ($inStar && in_array($k, ['allow', 'disallow'], true) && $v !== '') {
                $rules[] = [$k, $v];
            }
        }
        $best = null;
        $bestLen = -1;
        foreach ($rules as [$type, $pat]) {
            $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pat, '#')) . '#';
            if (preg_match($re, $path) && (strlen($pat) > $bestLen || (strlen($pat) === $bestLen && $type === 'allow'))) {
                $best = $type;
                $bestLen = strlen($pat);
            }
        }
        return $best !== 'disallow';
    }

    public static function indexable(string $url): array
    {
        $r = Fetcher::request($url);
        $p = new Page($r['body']);
        $reasons = [];
        $notes = [];
        if ($r['status'] < 200 || $r['status'] >= 300) {
            $reasons[] = "HTTP {$r['status']} (only 2xx pages are indexed)";
        }
        $xr = strtolower($r['headers']['x-robots-tag'] ?? '');
        if (str_contains($xr, 'noindex') || str_contains($xr, 'none')) {
            $reasons[] = 'X-Robots-Tag header says noindex';
        }
        foreach (['robots', 'googlebot'] as $name) {
            $m = strtolower($p->meta($name));
            if (str_contains($m, 'noindex') || preg_match('/(^|,)\s*none\s*(,|$)/', $m)) {
                $reasons[] = "<meta name=\"{$name}\"> says noindex";
            }
        }
        $fp = parse_url($r['final_url']);
        $path = ($fp['path'] ?? '/') . (isset($fp['query']) ? '?' . $fp['query'] : '');
        $robots = self::fetchOriginFile($r['final_url'], '/robots.txt');
        if ($robots['found'] && !self::robotsAllows($robots['body'], $path)) {
            $reasons[] = 'robots.txt blocks crawling of this path (Disallow)';
        }
        $canon = $p->link('canonical');
        $canonical = null;
        if ($canon !== '') {
            $canonical = Fetcher::resolveUrl($r['final_url'], $canon);
            $norm = fn(string $u) => rtrim(preg_replace('/#.*$/', '', strtolower($u)), '/');
            if ($norm($canonical) !== $norm($r['final_url'])) {
                $notes[] = "canonical points elsewhere ({$canonical}), so this URL may be dropped in favour of that one";
            }
        } else {
            $notes[] = 'no canonical tag';
        }
        if (count($r['hops']) > 1) {
            $notes[] = (count($r['hops']) - 1) . ' redirect(s) before the final URL';
        }
        $ok = !$reasons;
        $text = ($ok ? 'indexable: yes' : 'indexable: NO') . "\n";
        foreach ($reasons as $x) {
            $text .= "  blocked: {$x}\n";
        }
        foreach ($notes as $x) {
            $text .= "  note:    {$x}\n";
        }
        return ['text' => rtrim($text), 'data' => [
            'final_url' => $r['final_url'], 'indexable' => $ok, 'status' => $r['status'],
            'reasons' => $reasons, 'notes' => $notes, 'canonical' => $canonical,
        ]];
    }

    public static function links(string $url): array
    {
        $r = Fetcher::request($url);
        $p = new Page($r['body']);
        $base = $r['final_url'];
        $host = strtolower(parse_url($base, PHP_URL_HOST));
        $seen = [];
        foreach ($p->links() as $h) {
            if (preg_match('#^(mailto|tel|javascript|data|sms):#i', $h) || str_starts_with($h, '#')) {
                continue;
            }
            $abs = preg_replace('/#.*$/', '', Fetcher::resolveUrl($base, $h));
            if (!preg_match('#^https?://#i', $abs) || strlen($abs) > 1000 || str_contains($abs, '/cdn-cgi/')) {
                continue;
            }
            $seen[$abs] = strtolower((string) parse_url($abs, PHP_URL_HOST)) === $host;
        }
        arsort($seen); // internal links first
        $total = count($seen);
        $links = [];
        $checked = 0;
        foreach (array_slice($seen, 0, 30, true) as $abs => $internal) {
            if (Fetcher::$deadline - microtime(true) < 4) {
                break;
            }
            $entry = ['url' => $abs, 'internal' => $internal, 'status' => 0, 'error' => null];
            try {
                $x = Fetcher::request($abs, ['method' => 'HEAD', 'timeout' => 4]);
                if (in_array($x['status'], [403, 405, 501], true)) {
                    $x = Fetcher::request($abs, ['timeout' => 4, 'max_bytes' => 4096]);
                }
                $entry['status'] = $x['status'];
            } catch (FetchError $e) {
                $entry['error'] = $e->getMessage();
            }
            $entry['broken'] = $entry['status'] === 0 || $entry['status'] >= 400;
            $links[] = $entry;
            $checked++;
        }
        $broken = array_values(array_filter($links, fn($l) => $l['broken']));
        $lines = ["checked {$checked} of {$total} unique links, " . count($broken) . ' broken'];
        foreach ($broken as $b) {
            $lines[] = sprintf('  %s %s', $b['status'] ?: 'ERR', $b['url'] . ($b['error'] ? "  ({$b['error']})" : ''));
        }
        if ($total > $checked) {
            $lines[] = '  (limit: first 30 links checked, internal first)';
        }
        return ['text' => implode("\n", $lines), 'data' => [
            'final_url' => $base, 'total' => $total, 'checked' => $checked, 'broken' => count($broken), 'links' => $links,
        ]];
    }

    private const SCHEMA_REQUIRED = [
        'Organization' => ['name', 'url'], 'WebSite' => ['name', 'url'], 'WebPage' => ['name'],
        'Article' => ['headline', 'author', 'datePublished'], 'BlogPosting' => ['headline', 'author', 'datePublished'],
        'NewsArticle' => ['headline', 'author', 'datePublished'], 'Product' => ['name'],
        'FAQPage' => ['mainEntity'], 'BreadcrumbList' => ['itemListElement'],
        'LocalBusiness' => ['name', 'address'], 'Person' => ['name'], 'Event' => ['name', 'startDate', 'location'],
        'Recipe' => ['name', 'recipeIngredient'], 'VideoObject' => ['name', 'thumbnailUrl', 'uploadDate'],
    ];

    public static function schema(string $url): array
    {
        $r = Fetcher::request($url);
        [$blocks, $bad] = (new Page($r['body']))->jsonLd();
        $nodes = [];
        $walk = function ($n) use (&$walk, &$nodes) {
            if (!is_array($n)) {
                return;
            }
            if (isset($n['@graph']) && is_array($n['@graph'])) {
                foreach ($n['@graph'] as $g) {
                    $walk($g);
                }
                return;
            }
            if (array_is_list($n)) {
                foreach ($n as $g) {
                    $walk($g);
                }
                return;
            }
            if (isset($n['@type'])) {
                $nodes[] = $n;
            }
        };
        foreach ($blocks as $b) {
            $walk($b);
        }
        $items = [];
        foreach ($nodes as $n) {
            foreach ((array) $n['@type'] as $t) {
                $missing = [];
                foreach (self::SCHEMA_REQUIRED[$t] ?? [] as $prop) {
                    if (!isset($n[$prop]) || $n[$prop] === '' || $n[$prop] === []) {
                        $missing[] = $prop;
                    }
                }
                $items[] = ['type' => $t, 'missing' => $missing];
            }
        }
        $lines = [count($blocks) . ' JSON-LD block(s), ' . count($items) . ' typed item(s)' . ($bad ? ", {$bad} invalid JSON" : '')];
        foreach ($items as $i) {
            $lines[] = '  ' . sprintf('%-16s', $i['type']) . ($i['missing'] ? 'missing: ' . implode(', ', $i['missing']) : 'ok');
        }
        if ($bad) {
            $lines[] = "  {$bad} block(s) could not be parsed as JSON";
        }
        return ['text' => implode("\n", $lines), 'data' => [
            'final_url' => $r['final_url'], 'blocks' => count($blocks), 'invalid_blocks' => $bad,
            'types' => array_values(array_unique(array_column($items, 'type'))), 'items' => $items,
        ]];
    }

    public static function audit(string $url): array
    {
        return Audit::run($url);
    }
}
