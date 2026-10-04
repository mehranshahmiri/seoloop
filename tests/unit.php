<?php
// Run: php tests/unit.php   — offline unit tests (no network).
declare(strict_types=1);
require __DIR__ . '/../src/Fetcher.php';
require __DIR__ . '/../src/Page.php';
require __DIR__ . '/../src/Handlers.php';
require __DIR__ . '/../src/Audit.php';

use SeoLoop\Audit;
use SeoLoop\Fetcher;
use SeoLoop\Handlers;
use SeoLoop\Page;

$fail = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $fail;
    if ($got !== $want) {
        $fail++;
        echo "FAIL $name\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n";
    }
}

// Fetcher::normalize / resolveUrl
check('normalize adds https', Fetcher::normalize('Example.COM'), 'https://example.com/');
check('normalize keeps path+query', Fetcher::normalize('http://example.com/a?b=1'), 'http://example.com/a?b=1');
check('resolve relative', Fetcher::resolveUrl('https://x.com/a/b', 'c'), 'https://x.com/a/c');
check('resolve absolute path', Fetcher::resolveUrl('https://x.com/a/b', '/z'), 'https://x.com/z');
check('resolve protocol-relative', Fetcher::resolveUrl('https://x.com/', '//y.com/q'), 'https://y.com/q');

// robots.txt
$rb = "User-agent: Bing\nDisallow: /\n\nUser-agent: *\nDisallow: /private\nAllow: /private/ok\nDisallow: /*.pdf$\nSitemap: https://x.com/s.xml";
check('robots allow root', Handlers::robotsAllows($rb, '/'), true);
check('robots disallow prefix', Handlers::robotsAllows($rb, '/private/x'), false);
check('robots longest allow wins', Handlers::robotsAllows($rb, '/private/ok/y'), true);
check('robots wildcard+anchor', Handlers::robotsAllows($rb, '/a.pdf'), false);
check('robots anchor not matching', Handlers::robotsAllows($rb, '/a.pdf?x'), true);
check('robots other agent ignored', Handlers::robotsAllows("User-agent: Bing\nDisallow: /", '/'), true);
check('robots parse blocks_all', Handlers::parseRobots("User-agent: *\nDisallow: /")['blocks_all'], true);
check('robots parse sitemaps', Handlers::parseRobots($rb)['sitemaps'], ['https://x.com/s.xml']);

// Page
$html = '<!doctype html><html lang="en"><head><title> Hi  there </title><meta name="Description" content="d"><link rel="canonical" href="/c">'
    . '<script type="application/ld+json">{"@type":"Organization","name":"A"}</script><script type="application/ld+json">{bad</script></head>'
    . '<body><h1>One</h1><img src="a.png"><a href="/x">x</a><script>var a="many words here";</script><p>hello big world</p></body></html>';
$p = new Page($html);
check('page title', $p->title(), 'Hi there');
check('page meta case-insensitive', $p->meta('description'), 'd');
check('page canonical', $p->link('canonical'), '/c');
check('page lang', $p->lang(), 'en');
check('page h1', $p->count('h1'), 1);
check('page img alt', $p->imagesMissingAlt(), 1);
check('page links', $p->links(), ['/x']);
[$blocks, $bad] = $p->jsonLd();
check('jsonld good', count($blocks), 1);
check('jsonld bad', $bad, 1);
check('words: body only, scripts ignored', $p->words(), 5);

// Audit::present — scoring, filtering, formats
$raw = ['url' => 'https://x.com/', 'checks' => [
    ['category' => 'seo', 'id' => 'a', 'importance' => 'high', 'label' => 'A', 'passed' => true, 'detail' => ''],
    ['category' => 'seo', 'id' => 'b', 'importance' => 'medium', 'label' => 'B', 'passed' => false, 'detail' => 'x|y'],
    ['category' => 'security', 'id' => 'c', 'importance' => 'low', 'label' => 'C', 'passed' => true, 'detail' => ''],
]];
$all = Audit::present($raw)['data'];
check('score weighted', $all['score'], (int) round(100 * 12 / 17));
check('counts', [$all['passed'], $all['failed']], [2, 1]);
check('only category', Audit::present($raw, ['only' => ['security']])['data']['score'], 100);
check('skip check id', Audit::present($raw, ['skip' => ['b']])['data']['score'], 100);
check('csv header', explode("\n", Audit::present($raw, ['format' => 'csv'])['text'])[0], 'category,id,importance,passed,label,detail');
check('md escapes pipe', str_contains(Audit::present($raw, ['format' => 'md'])['text'], 'x\|y'), true);
check('grades', [Audit::grade(95), Audit::grade(85), Audit::grade(75), Audit::grade(65), Audit::grade(10)], ['A', 'B', 'C', 'D', 'F']);

echo $fail ? "FAILED ($fail)\n" : "ok - all unit tests passed\n";
exit($fail ? 1 : 0);
