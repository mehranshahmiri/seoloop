<?php
declare(strict_types=1);

namespace SeoLoop;

/** Runs an endpoint handler behind the file cache. Shared by the HTTP router and the MCP server. */
final class Runner
{
    public static string $dir = '/var/cache/seoloop-api';

    /** @return array{text:string,data:array,cached:bool} */
    public static function run(string $endpoint, string $target, ?string $vs = null): array
    {
        if (!isset(Handlers::ENDPOINTS[$endpoint])) {
            throw new FetchError("Unknown endpoint '{$endpoint}'.", 404);
        }
        if ($endpoint === 'compare' && ($vs === null || $vs === '')) {
            throw new FetchError('usage: /compare/<a>?vs=<b>', 400);
        }
        $key = hash('sha256', $endpoint . '|' . Fetcher::normalize($target) . '|' . ($vs !== null ? Fetcher::normalize($vs) : ''));
        $file = self::$dir . "/{$key}.json";
        $ttl = in_array($endpoint, ['audit', 'compare'], true) ? 600 : 300;
        if (is_file($file) && filemtime($file) > time() - $ttl) {
            $hit = json_decode((string) file_get_contents($file), true);
            if (is_array($hit) && isset($hit['text'], $hit['data'])) {
                return $hit + ['cached' => true];
            }
        }
        $result = $endpoint === 'compare' ? Handlers::compare($target, (string) $vs) : Handlers::$endpoint($target);
        if (is_dir(self::$dir) && is_writable(self::$dir)) {
            file_put_contents($file, json_encode($result), LOCK_EX);
            if (random_int(1, 100) === 1) {
                foreach (glob(self::$dir . '/*.json') ?: [] as $f) {
                    if (filemtime($f) < time() - 3600) {
                        @unlink($f);
                    }
                }
            }
        }
        return $result + ['cached' => false];
    }
}
