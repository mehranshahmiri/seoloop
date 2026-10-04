<?php
declare(strict_types=1);

namespace SeoLoop;

/**
 * Minimal stateless MCP server (Streamable HTTP, JSON responses only).
 * Exposes every endpoint as a tool so AI agents can run SEO checks directly.
 */
final class Mcp
{
    private const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @return array[] */
    public static function tools(): array
    {
        $tools = [];
        foreach (Handlers::ENDPOINTS as $name => $desc) {
            $props = ['target' => ['type' => 'string', 'description' => 'Domain or URL to check, e.g. example.com or https://example.com/pricing']];
            $req = ['target'];
            if ($name === 'compare') {
                $props['vs'] = ['type' => 'string', 'description' => 'Second domain or URL to compare against'];
                $req[] = 'vs';
            }
            if ($name === 'audit') {
                $props['only'] = ['type' => 'string', 'description' => 'Comma-separated categories (seo, performance, security, crawlability) or check ids to include'];
                $props['skip'] = ['type' => 'string', 'description' => 'Comma-separated categories or check ids to exclude'];
            }
            $tools[] = [
                'name' => $name,
                'description' => $desc . '. Fetches the live public page.',
                'inputSchema' => ['type' => 'object', 'properties' => $props, 'required' => $req],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => true],
            ];
        }
        return $tools;
    }

    /** Handle one JSON-RPC message. Returns null for notifications (no response). */
    public static function handle(mixed $msg): ?array
    {
        if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) {
            return self::error($msg['id'] ?? null, -32600, 'Invalid Request');
        }
        $id = $msg['id'] ?? null;
        $isNotification = !array_key_exists('id', $msg);
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        switch ($msg['method']) {
            case 'initialize':
                $want = (string) ($params['protocolVersion'] ?? '');
                return self::ok($id, [
                    'protocolVersion' => in_array($want, self::VERSIONS, true) ? $want : self::VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'seoloop', 'title' => 'SEO Loop', 'version' => Handlers::VERSION],
                    'instructions' => 'SEO checks for any public URL. Start with the audit tool for a scored report; use indexable, links, schema, ssl, redirects and compare for specifics.',
                ]);
            case 'ping':
                return self::ok($id, new \stdClass());
            case 'tools/list':
                return self::ok($id, ['tools' => self::tools()]);
            case 'tools/call':
                return self::ok($id, self::call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []));
        }
        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled, ...
        }
        return self::error($id, -32601, 'Method not found');
    }

    private static function call(string $name, array $args): array
    {
        $fail = fn(string $m) => ['content' => [['type' => 'text', 'text' => $m]], 'isError' => true];
        if (!isset(Handlers::ENDPOINTS[$name])) {
            return $fail("Unknown tool '{$name}'.");
        }
        $target = $args['target'] ?? null;
        if (!is_string($target) || $target === '') {
            return $fail("Missing required argument 'target'.");
        }
        $vs = isset($args['vs']) && is_string($args['vs']) ? $args['vs'] : null;
        try {
            $r = Runner::run($name, $target, $vs);
        } catch (FetchError $e) {
            return $fail($e->getMessage());
        } catch (\Throwable $e) {
            return $fail('Internal error while running the check.');
        }
        $text = $r['text'];
        $data = $r['data'];
        if ($name === 'audit') {
            $list = fn($k) => array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($args[$k] ?? ''))))));
            if ($list('only') || $list('skip')) {
                $p = Audit::present(['url' => $data['url'], 'checks' => $data['checks']], ['only' => $list('only'), 'skip' => $list('skip')]);
                [$text, $data] = [$p['text'], $p['data']];
            }
        }
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $data, 'isError' => false];
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
