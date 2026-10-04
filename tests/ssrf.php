<?php
// Run: php tests/ssrf.php   — every target below MUST be refused before any connection is made.
declare(strict_types=1);
require __DIR__ . '/../src/Fetcher.php';

use SeoLoop\FetchError;
use SeoLoop\Fetcher;

$bad = [
    'localhost', '127.0.0.1', '127.1.2.3', '10.0.0.1', '172.16.5.5', '192.168.0.1', '169.254.169.254',
    '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '[::1]', '[::ffff:127.0.0.1]',
    'file:///etc/passwd', 'gopher://x.com', 'http://user:pw@example.com', 'example.com:8080', 'example.com:22',
    'http://127.0.0.1.nip.io', "exa mple.com", '', str_repeat('a', 3000),
];
$fail = 0;
foreach ($bad as $t) {
    try {
        $url = Fetcher::normalize($t);
        Fetcher::safeIp(parse_url($url, PHP_URL_HOST));
        echo "NOT BLOCKED: $t\n";
        $fail++;
    } catch (FetchError $e) {
    }
}
foreach (['10.1.1.1', '127.0.0.1', '::1', '::ffff:10.0.0.1', 'fe80::1', 'fd00::1', '169.254.1.1'] as $ip) {
    if (!Fetcher::isBlocked($ip)) {
        echo "IP NOT BLOCKED: $ip\n";
        $fail++;
    }
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'] as $ip) {
    if (Fetcher::isBlocked($ip)) {
        echo "PUBLIC IP WRONGLY BLOCKED: $ip\n";
        $fail++;
    }
}
echo $fail ? "FAILED ($fail)\n" : "ok - all SSRF cases refused\n";
exit($fail ? 1 : 0);
