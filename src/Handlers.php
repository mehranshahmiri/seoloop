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

    public static function audit(string $url): array
    {
        return Audit::run($url);
    }
}
