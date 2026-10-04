<?php
declare(strict_types=1);

namespace SeoLoop;

/** Single-page technical SEO audit: ~25 checks in 4 categories, weighted into a 0-100 score. */
final class Audit
{
    private const WEIGHT = ['high' => 10, 'medium' => 5, 'low' => 2];
    private array $checks = [];

    public static function run(string $url): array
    {
        return (new self())->execute($url);
    }

    private function add(string $cat, string $id, string $importance, string $label, bool $pass, string $detail = ''): void
    {
        $this->checks[] = ['category' => $cat, 'id' => $id, 'importance' => $importance, 'label' => $label, 'passed' => $pass, 'detail' => $detail];
    }

    private function execute(string $url): array
    {
        $r = Fetcher::request($url);
        $page = new Page($r['body']);
        $h = $r['headers'];
        $final = parse_url($r['final_url']);
        $host = $final['host'];
        $isHttps = $final['scheme'] === 'https';
        $title = $page->title();
        $desc = $page->meta('description');
        $robotsMeta = strtolower($page->meta('robots'));
        $h1 = $page->count('h1');
        $words = $page->words();
        $ttfb = $r['timing']['ttfb_ms'];
        $xrobots = strtolower($h['x-robots-tag'] ?? '');
        $noindex = str_contains($robotsMeta, 'noindex') || str_contains($xrobots, 'noindex');

        // --- SEO -------------------------------------------------------
        $this->add('seo', 'title', 'high', 'Title tag present', $title !== '', $title === '' ? 'No <title> found.' : '');
        $tl = mb_strlen($title);
        $this->add('seo', 'title_length', 'medium', 'Title 10-60 characters', $tl >= 10 && $tl <= 60, $title !== '' ? "{$tl} characters." : '');
        $this->add('seo', 'description', 'high', 'Meta description present', $desc !== '', $desc === '' ? 'No meta description.' : '');
        $dl = mb_strlen($desc);
        $this->add('seo', 'description_length', 'medium', 'Description 50-160 characters', $dl >= 50 && $dl <= 160, $desc !== '' ? "{$dl} characters." : '');
        $this->add('seo', 'h1', 'medium', 'Exactly one H1', $h1 === 1, "Found {$h1} H1 tag(s).");
        $this->add('seo', 'canonical', 'medium', 'Canonical link', $page->link('canonical') !== '', 'No rel=canonical.');
        $this->add('seo', 'indexable', 'high', 'Page is indexable', !$noindex, $noindex ? 'noindex via meta robots or X-Robots-Tag.' : '');
        $this->add('seo', 'lang', 'low', 'html lang attribute', $page->lang() !== '', '');
        $this->add('seo', 'h2', 'low', 'Has H2 subheadings', $page->count('h2') > 0, '');
        $noAlt = $page->imagesMissingAlt();
        $this->add('seo', 'img_alt', 'medium', 'Images have alt attributes', $noAlt === 0, "{$noAlt} image(s) missing alt.");
        $this->add('seo', 'content', 'medium', 'At least 300 words of content', $words >= 300, "{$words} words.");
        $this->add('seo', 'open_graph', 'low', 'Open Graph title + image', $page->prop('og:title') !== '' && $page->prop('og:image') !== '', 'Missing og:title or og:image.');

        // --- Performance -----------------------------------------------
        $this->add('performance', 'status', 'high', 'Responds 200 OK', $r['status'] === 200, "HTTP {$r['status']}.");
        $this->add('performance', 'ttfb', 'medium', 'TTFB under 800 ms', $ttfb < 800, "{$ttfb} ms.");
        $this->add('performance', 'viewport', 'high', 'Mobile viewport meta', $page->meta('viewport') !== '', 'No viewport meta tag.');
        $enc = strtolower($h['content-encoding'] ?? '');
        $this->add('performance', 'compression', 'medium', 'Compressed response (gzip/br)', $enc !== '', 'No Content-Encoding.');
        $kb = (int) round(strlen($r['body']) / 1024);
        $this->add('performance', 'html_size', 'low', 'HTML under 500 KB', strlen($r['body']) < 512_000 && !$r['truncated'], "{$kb} KB.");

        // --- Security --------------------------------------------------
        $this->add('security', 'https', 'high', 'Served over HTTPS', $isHttps, '');
        $this->add('security', 'tls_valid', 'high', 'Valid TLS certificate', $isHttps && $r['tls_error'] === null, (string) $r['tls_error']);
        $this->add('security', 'http_redirect', 'medium', 'HTTP redirects to HTTPS', $this->httpRedirects($host), 'http:// does not redirect to https://.');
        $this->add('security', 'hsts', 'medium', 'HSTS header', isset($h['strict-transport-security']), '');
        $this->add('security', 'nosniff', 'low', 'X-Content-Type-Options', isset($h['x-content-type-options']), '');
        $framing = isset($h['x-frame-options']) || str_contains(strtolower($h['content-security-policy'] ?? ''), 'frame-ancestors');
        $this->add('security', 'framing', 'low', 'Clickjacking protection', $framing, 'No X-Frame-Options or frame-ancestors.');

        // --- Crawlability ----------------------------------------------
        $robots = $sitemap = null;
        try {
            $robots = Handlers::robots($r['final_url'])['data'];
        } catch (FetchError $e) {
        }
        try {
            $sitemap = Handlers::sitemap($r['final_url'])['data'];
        } catch (FetchError $e) {
        }
        $this->add('crawlability', 'robots_txt', 'medium', 'robots.txt exists', (bool) ($robots['found'] ?? false), '');
        $this->add('crawlability', 'robots_open', 'high', 'robots.txt does not block everything', !($robots['blocks_all'] ?? false), 'User-agent: * / Disallow: /');
        $this->add('crawlability', 'sitemap', 'medium', 'XML sitemap found', (bool) ($sitemap['found'] ?? false), '');

        return self::present(['url' => $r['final_url'], 'checks' => $this->checks]);
    }

    private function httpRedirects(string $host): bool
    {
        try {
            $r = Fetcher::request('http://' . $host . '/', ['method' => 'HEAD', 'follow' => false]);
        } catch (FetchError $e) {
            return false;
        }
        return in_array($r['status'], [301, 302, 307, 308], true) && str_starts_with(strtolower($r['headers']['location'] ?? ''), 'https://');
    }

    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }

    /**
     * Turn raw checks into the final result, optionally narrowed to categories/check ids.
     * Cached results hold every check, so ?only= / ?skip= / ?format= are applied here, after the cache.
     *
     * @param array{url:string,checks:array} $raw
     * @param array{only?:string[],skip?:string[],format?:string} $o
     * @return array{text:string,data:array}
     */
    public static function present(array $raw, array $o = []): array
    {
        $checks = $raw['checks'];
        if (!empty($o['only'])) {
            $checks = array_values(array_filter($checks, fn($c) => in_array($c['category'], $o['only'], true) || in_array($c['id'], $o['only'], true)));
        }
        if (!empty($o['skip'])) {
            $checks = array_values(array_filter($checks, fn($c) => !in_array($c['category'], $o['skip'], true) && !in_array($c['id'], $o['skip'], true)));
        }
        $earned = $total = 0;
        $cats = [];
        foreach ($checks as $c) {
            $w = self::WEIGHT[$c['importance']];
            $total += $w;
            $earned += $c['passed'] ? $w : 0;
            $cats[$c['category']]['w'] = ($cats[$c['category']]['w'] ?? 0) + $w;
            $cats[$c['category']]['e'] = ($cats[$c['category']]['e'] ?? 0) + ($c['passed'] ? $w : 0);
        }
        $score = (int) round(100 * $earned / max(1, $total));
        $catScores = array_map(fn($c) => (int) round(100 * $c['e'] / $c['w']), $cats);
        $passed = count(array_filter($checks, fn($c) => $c['passed']));
        $data = [
            'url' => $raw['url'], 'score' => $score, 'grade' => self::grade($score), 'categories' => $catScores,
            'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks,
        ];
        $text = match ($o['format'] ?? 'text') {
            'csv' => self::renderCsv($checks),
            'md', 'markdown' => self::renderMarkdown($data),
            default => self::renderText($data),
        };
        return ['text' => $text, 'data' => $data];
    }

    private static function renderText(array $d): string
    {
        $out = ["SEO Loop audit  {$d['url']}", '', sprintf('Score: %d/100  (%s)    %d passed, %d failed', $d['score'], $d['grade'], $d['passed'], $d['failed']), ''];
        $out[] = 'Categories: ' . implode('   ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($d['categories']), $d['categories']));
        $out[] = '';
        $last = '';
        foreach ($d['checks'] as $c) {
            if ($c['category'] !== $last) {
                $last = $c['category'];
                $out[] = strtoupper($last);
            }
            $line = sprintf('  %s  %-8s %s', $c['passed'] ? 'PASS' : 'FAIL', $c['passed'] ? '' : "[{$c['importance']}]", $c['label']);
            if (!$c['passed'] && $c['detail'] !== '') {
                $line .= " - {$c['detail']}";
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private static function renderCsv(array $checks): string
    {
        $fh = fopen('php://memory', 'w+');
        fputcsv($fh, ['category', 'id', 'importance', 'passed', 'label', 'detail'], ',', '"', '');
        foreach ($checks as $c) {
            fputcsv($fh, [$c['category'], $c['id'], $c['importance'], $c['passed'] ? 'true' : 'false', $c['label'], $c['detail']], ',', '"', '');
        }
        rewind($fh);
        return rtrim((string) stream_get_contents($fh));
    }

    /** Markdown suited to a PR comment or GitHub step summary. */
    private static function renderMarkdown(array $d): string
    {
        $out = ["## SEO Loop audit: {$d['score']}/100 ({$d['grade']})", '', "`{$d['url']}` - {$d['passed']} passed, {$d['failed']} failed", ''];
        $out[] = '| Category | Score |';
        $out[] = '|---|---|';
        foreach ($d['categories'] as $k => $v) {
            $out[] = "| {$k} | {$v} |";
        }
        $failed = array_filter($d['checks'], fn($c) => !$c['passed']);
        if ($failed) {
            $out[] = '';
            $out[] = '### Failing checks';
            $out[] = '';
            $out[] = '| Importance | Check | Detail |';
            $out[] = '|---|---|---|';
            foreach ($failed as $c) {
                $out[] = "| {$c['importance']} | {$c['label']} | " . str_replace('|', '\\|', $c['detail']) . ' |';
            }
        }
        return implode("\n", $out);
    }
}
