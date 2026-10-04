<p align="center">
  <a href="https://seoloop.in"><img src="public/logo.png" alt="SEO Loop" width="320"></a>
</p>

<h3 align="center">SEO checks for your terminal.</h3>

<p align="center">
  Audit any website from the command line. Plain text for humans, JSON for scripts, an exit code for CI.<br>
  Free, open source, no signup, no API key.
</p>

<p align="center">
  <a href="https://seoloop.in"><b>seoloop.in</b></a> &nbsp;·&nbsp;
  <a href="#install">Install</a> &nbsp;·&nbsp;
  <a href="#endpoints">Endpoints</a> &nbsp;·&nbsp;
  <a href="#self-host">Self-host</a> &nbsp;·&nbsp;
  <a href="CONTRIBUTING.md">Contribute</a>
</p>

<p align="center">
  <img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-730094">
  <img alt="PHP 8.2+" src="https://img.shields.io/badge/PHP-8.2%2B-730094">
  <img alt="No database, no framework" src="https://img.shields.io/badge/deps-none-730094">
  <img alt="PRs welcome" src="https://img.shields.io/badge/PRs-welcome-730094">
</p>

---

```console
$ curl seoloop.in/audit/example.com
SEO Loop audit  https://example.com/

Score: 81/100  (B)    20 passed, 6 failed
Categories: seo 80   performance 90   security 50   crawlability 75

SEO
  PASS           Title tag present
  FAIL  [high]   Meta description present - No meta description.
  ...
```

## What is it?

SEO Loop is a small HTTP service that answers SEO questions about any public URL in one request, like `ifconfig.me` does for your IP address. It checks the things that decide whether a page can be found and trusted: title and meta tags, headings, canonical and robots rules, redirects, response time, TLS, security headers, `robots.txt` and sitemaps.

- **Terminal first.** Every answer is short plain text by default. No dashboard, no accounts.
- **Scriptable.** JSON on request, a single field with `?field=`, and an HTTP 412 gate for CI.
- **Hackable.** Plain PHP, no framework, no database. Adding a check is one line.
- **Safe to expose.** Built to fetch URLs from strangers: see the [security model](#security-model).

## Install

```sh
# no install, just curl
curl seoloop.in/audit/example.com

# or install the CLI (one shell script, needs only curl)
curl -fsSL https://seoloop.in/install | sh
seoloop audit example.com
```

The installer puts `seoloop` in `/usr/local/bin`, or `~/.local/bin` if that isn't writable. Read the script first if you like: [`install.sh`](install.sh) and [`bin/seoloop`](bin/seoloop).

```console
$ seoloop --help
$ seoloop ssl example.com --field days_left
$ seoloop audit example.com --json
$ seoloop audit example.com --min 80 || echo "SEO score too low"
```

## Endpoints

| Endpoint | Returns |
|---|---|
| `/audit/<target>` | 26 checks in 4 categories, a 0-100 score and a grade |
| `/status/<target>` | HTTP status after redirects |
| `/title/<target>` | page title |
| `/meta/<target>` | title, description, canonical, robots, Open Graph, headings, word count |
| `/headers/<target>` | final response headers |
| `/redirects/<target>` | redirect chain |
| `/ttfb/<target>` | time to first byte, with DNS / connect / TLS split |
| `/ssl/<target>` | TLS certificate: issuer, expiry, days left, protocol |
| `/robots/<target>` | robots.txt, parsed |
| `/sitemap/<target>` | sitemap discovery and URL count |
| `/ip`, `/ua` | your IP and user agent |

`<target>` is a domain or a URL (`example.com`, `https://example.com/pricing`).

### Output options

| | |
|---|---|
| JSON | `?json`, header `Accept: application/json`, or `/v1/<endpoint>/<target>` |
| One value | `?field=days_left` (any top-level scalar in the JSON) |
| CI gate | `?min=80` on `/audit` answers HTTP 412 below 80. `seoloop audit x.com --min 80` exits 3 |
| Colour | `?color` on `/audit` (the CLI does this automatically on a TTY) |

Rate limits are enforced per IP by nginx (see `deploy/nginx-limits.conf`). Results are cached for 5 to 10 minutes.

## Self-host

Needs PHP 8.2+ with `curl`, `dom`, `openssl`, `mbstring`, `intl`; nginx; PHP-FPM.

```sh
git clone https://github.com/mehranshahmiri/seoloop /var/www/seoloop-api
sudo mkdir -p /var/cache/seoloop-api && sudo chown www-data: /var/cache/seoloop-api
sudo cp deploy/php-fpm-pool.conf /etc/php/8.x/fpm/pool.d/seoloop-api.conf
sudo cp deploy/nginx-limits.conf /etc/nginx/conf.d/seoloop-limits.conf
sudo cp deploy/seoloop-fastcgi.conf /etc/nginx/snippets/
# adapt deploy/nginx-site.conf to your domain and TLS, then:
sudo nginx -t && sudo systemctl reload nginx php8.x-fpm
```

Point the CLI at your own instance with `SEOLOOP_URL=https://seo.example.com seoloop audit example.com`.

For quick local hacking: `php -S 127.0.0.1:8099 -t public public/index.php`.

## Security model

This service fetches URLs that strangers type in, so `src/Fetcher.php` is the only way out and it is strict:

- only `http`/`https`, only ports 80 and 443, no credentials in URLs, no IPv6 literals
- the hostname is resolved first and **every** returned address must be public (RFC 1918, loopback, link-local, CGNAT, multicast, reserved and mapped IPv6 ranges are refused)
- the validated IP is pinned into curl, so DNS can't change between the check and the connection
- redirects are followed manually, and every hop is re-validated
- response size, time and redirect count are capped; a global 25 s deadline covers a whole request

Run `php tests/ssrf.php` after changing anything near the fetcher. Found a hole? See [SECURITY.md](SECURITY.md).

## Add a check

Audit checks live in `src/Audit.php`. One line per check:

```php
$this->add('seo', 'my_check', 'medium', 'Human label', $passed, 'Detail shown on failure');
```

To add an endpoint, write a static method on `SeoLoop\Handlers` returning `['text' => ..., 'data' => [...]]` and list it in `Handlers::ENDPOINTS`. Always fetch through `Fetcher::request()`, never `file_get_contents` or your own curl handle.

## Layout

```
public/index.php    router, caching, output formats
public/landing.html the page browsers see at /
src/Fetcher.php     SSRF-hardened HTTP client + TLS peek
src/Handlers.php    one method per endpoint
src/Audit.php       the audit engine
src/Page.php        HTML parsing helpers
bin/seoloop         the CLI
install.sh          CLI installer
deploy/             nginx + php-fpm examples
tests/ssrf.php      must-refuse target list
```


## Contributing

Pull requests are very welcome: new checks, new endpoints, fixes, docs. Start with [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE). A free product by [OpenLoop](https://openloop.in), created by [Mehran Shahmiri](https://mehranshahmiri.com).
