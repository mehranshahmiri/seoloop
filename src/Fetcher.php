<?php
declare(strict_types=1);

namespace SeoLoop;

final class FetchError extends \RuntimeException
{
    /** @param int $code HTTP status to answer with: 422 = bad/blocked target, 502 = upstream failed, 504 = out of time */
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}

/**
 * The only door out of this service. Every outbound request goes through here, and every hop
 * (including each redirect) is re-validated: scheme, port, and the resolved IP must be public.
 * The validated IP is pinned into curl (CURLOPT_RESOLVE) so DNS can't change between check and connect.
 */
final class Fetcher
{
    public const MAX_BYTES = 1_500_000;
    public const MAX_REDIRECTS = 6;
    public const TIMEOUT = 8;

    public static float $deadline = 0.0;

    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    private const BLOCKED_V6 = [
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** Validate + canonicalise a user-supplied target into an http(s) URL. */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 2048 || preg_match('/[\x00-\x20\x7f]/', $raw)) {
            throw new FetchError('Invalid target. Use a domain or URL, e.g. example.com');
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $p = parse_url($raw);
        if ($p === false || empty($p['host'])) {
            throw new FetchError('Invalid target. Use a domain or URL, e.g. example.com');
        }
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FetchError('Only http and https targets are supported.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new FetchError('Credentials in the URL are not allowed.');
        }
        if (isset($p['port']) && !in_array($p['port'], [80, 443], true)) {
            throw new FetchError('Only ports 80 and 443 are allowed.');
        }
        $host = strtolower(rtrim($p['host'], '.'));
        if (str_contains($host, '[') || str_contains($host, ':')) {
            throw new FetchError('IPv6 literals are not supported.');
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new FetchError('Invalid hostname.');
            }
            $host = $ascii;
        }
        $isIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (!$isIp && (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host))) {
            throw new FetchError('Invalid hostname.');
        }
        $url = $scheme . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/');
        if (isset($p['query']) && $p['query'] !== '') {
            $url .= '?' . $p['query'];
        }
        return $url;
    }

    /** Resolve $host and return one public IP. Refuses if ANY returned address is non-public. */
    public static function safeIp(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $recs = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $ips = [];
            foreach ($recs as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                } elseif (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new FetchError("Could not resolve {$host}.", 502);
        }
        foreach ($ips as $ip) {
            if (self::isBlocked($ip)) {
                throw new FetchError('That host resolves to a private or reserved address.');
            }
        }
        usort($ips, fn($a, $b) => (int) str_contains($a, ':') <=> (int) str_contains($b, ':')); // prefer IPv4
        return $ips[0];
    }

    public static function isBlocked(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        foreach (strlen($bin) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $nb = inet_pton($net);
        if (strlen($nb) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
            return false;
        }
        if ($rem) {
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) !== (ord($nb[$bytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    public static function resolveUrl(string $base, string $loc): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($loc, '//')) {
            return $p['scheme'] . ':' . $loc;
        }
        if (str_starts_with($loc, '/')) {
            return $origin . $loc;
        }
        if (str_starts_with($loc, '?')) {
            return $origin . ($p['path'] ?? '/') . $loc;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        return $origin . $dir . $loc;
    }

    /**
     * @param array{method?:string,follow?:bool,max_bytes?:int} $o
     * @return array<string,mixed>
     */
    public static function request(string $url, array $o = []): array
    {
        $method = $o['method'] ?? 'GET';
        $follow = $o['follow'] ?? true;
        $maxBytes = $o['max_bytes'] ?? self::MAX_BYTES;
        $input = $url;
        $current = self::normalize($url);
        $hops = [];
        $tlsError = null;
        $firstDns = null;

        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            $p = parse_url($current);
            $host = $p['host'];
            $https = $p['scheme'] === 'https';
            $port = $p['port'] ?? ($https ? 443 : 80);

            $t0 = microtime(true);
            $ip = self::safeIp($host);
            $dnsMs = (microtime(true) - $t0) * 1000;
            $firstDns ??= $dnsMs;

            $remaining = self::$deadline - microtime(true);
            if ($remaining < 1.5) {
                throw new FetchError('Ran out of time budget for this request.', 504);
            }

            $verify = true;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $raw = [];
                $headers = [];
                $body = '';
                $trunc = false;
                $ch = curl_init($current);
                curl_setopt_array($ch, [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_NOBODY => $method === 'HEAD',
                    CURLOPT_TIMEOUT_MS => (int) (min(self::TIMEOUT, $remaining) * 1000),
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SEOLoopBot/1.0; +https://seoloop.in)',
                    CURLOPT_ENCODING => '',
                    CURLOPT_SSL_VERIFYPEER => $verify,
                    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5'],
                    CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$raw, &$headers): int {
                        $len = strlen($line);
                        $line = rtrim($line, "\r\n");
                        if ($line === '') {
                            return $len;
                        }
                        if (str_starts_with($line, 'HTTP/')) {
                            $raw = [];
                            $headers = [];
                            return $len;
                        }
                        if (str_contains($line, ':')) {
                            [$k, $v] = explode(':', $line, 2);
                            $k = trim($k);
                            $v = trim($v);
                            $raw[] = "{$k}: {$v}";
                            $lk = strtolower($k);
                            $headers[$lk] = isset($headers[$lk]) ? $headers[$lk] . ', ' . $v : $v;
                        }
                        return $len;
                    },
                    CURLOPT_WRITEFUNCTION => function ($c, string $d) use (&$body, &$trunc, $maxBytes): int {
                        if (strlen($body) + strlen($d) > $maxBytes) {
                            $body .= substr($d, 0, $maxBytes - strlen($body));
                            $trunc = true;
                            return -1;
                        }
                        $body .= $d;
                        return strlen($d);
                    },
                ]);
                curl_exec($ch);
                $errno = curl_errno($ch);
                $err = curl_error($ch);
                $info = curl_getinfo($ch);

                if ($errno === 0 || ($trunc && $errno === 23)) {
                    break;
                }
                if ($verify && in_array($errno, [51, 58, 59, 60, 64, 66, 77, 83, 90, 91], true)) {
                    $tlsError = $err; // bad certificate: report it, but keep auditing the page
                    $verify = false;
                    continue;
                }
                throw new FetchError($errno === 28 ? "Timed out reaching {$host}." : "Could not connect to {$host}: {$err}", $errno === 28 ? 504 : 502);
            }

            $status = (int) $info['http_code'];
            $hops[] = [
                'url' => $current,
                'status' => $status,
                'ip' => $ip,
                'location' => $headers['location'] ?? null,
                'time_ms' => (int) round($info['total_time'] * 1000),
            ];

            $isRedirect = in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location']);
            if ($follow && $isRedirect) {
                $current = self::normalize(self::resolveUrl($current, $headers['location']));
                continue;
            }

            return [
                'url' => $input,
                'final_url' => $current,
                'status' => $status,
                'headers' => $headers,
                'raw_headers' => $raw,
                'body' => $body,
                'truncated' => $trunc,
                'hops' => $hops,
                'ip' => $ip,
                'protocol' => $info['http_version'] ?? null,
                'tls_error' => $tlsError,
                'timing' => [
                    'dns_ms' => (int) round($firstDns),
                    'connect_ms' => (int) round(($info['connect_time'] ?? 0) * 1000),
                    'tls_ms' => $https ? max(0, (int) round((($info['appconnect_time'] ?? 0) - ($info['connect_time'] ?? 0)) * 1000)) : 0,
                    'ttfb_ms' => (int) round(($info['starttransfer_time'] ?? 0) * 1000 + $dnsMs),
                    'total_ms' => (int) round(($info['total_time'] ?? 0) * 1000 + $dnsMs),
                ],
            ];
        }
        throw new FetchError('Too many redirects.', 502);
    }

    /** Peek at the TLS certificate on :443 of a validated host. */
    public static function certificate(string $host): array
    {
        $ip = self::safeIp($host);
        $remaining = self::$deadline - microtime(true);
        $timeout = max(1, min(6, (int) $remaining));
        $connect = function (bool $verify) use ($ip, $host, $timeout) {
            $ctx = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ]]);
            $target = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            return @stream_socket_client("ssl://{$target}:443", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        };
        $trusted = true;
        $s = $connect(true);
        if (!$s) {
            $trusted = false;
            $s = $connect(false);
        }
        if (!$s) {
            throw new FetchError("No TLS service answering on {$host}:443.", 502);
        }
        $params = stream_context_get_params($s);
        $meta = stream_get_meta_data($s);
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        fclose($s);
        if (!$cert) {
            throw new FetchError('Could not read the certificate.', 502);
        }
        $san = [];
        foreach (explode(',', $cert['extensions']['subjectAltName'] ?? '') as $n) {
            $n = trim($n);
            if (str_starts_with($n, 'DNS:')) {
                $san[] = substr($n, 4);
            }
        }
        return [
            'host' => $host,
            'trusted' => $trusted,
            'subject' => $cert['subject']['CN'] ?? null,
            'issuer' => trim(($cert['issuer']['O'] ?? '') . ' ' . ($cert['issuer']['CN'] ?? '')),
            'valid_from' => gmdate('Y-m-d', $cert['validFrom_time_t']),
            'valid_to' => gmdate('Y-m-d', $cert['validTo_time_t']),
            'days_left' => (int) floor(($cert['validTo_time_t'] - time()) / 86400),
            'protocol' => $meta['crypto']['protocol'] ?? null,
            'cipher' => $meta['crypto']['cipher_name'] ?? null,
            'san' => $san,
        ];
    }
}
