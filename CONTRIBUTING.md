# Contributing to SEO Loop

Thanks for helping. The project is deliberately small: plain PHP, no framework, no database, no build step.

## Run it locally

```sh
git clone https://github.com/mehranshahmiri/seoloop && cd seoloop
php -S 127.0.0.1:8099 -t public public/index.php
curl 127.0.0.1:8099/audit/example.com
```

Needs PHP 8.2+ with `curl`, `dom`, `openssl`, `mbstring`, `intl`.

## Ground rules

1. **All outbound requests go through `Fetcher::request()`** (or `Fetcher::certificate()`). Never call `file_get_contents`, `curl_init` or `stream_socket_client` on a user-supplied host anywhere else. This service fetches URLs that strangers type in, and the fetcher is what keeps it from being used to reach internal networks.
2. **Run `php tests/ssrf.php`** before opening a PR. It must print `ok`.
3. **No new dependencies** without a good reason. No Composer packages, no JS build.
4. **Keep answers short.** The default (plain text) output of an endpoint should fit a terminal screen.

## Add an audit check

In `src/Audit.php`, one line per check:

```php
$this->add('seo', 'my_check', 'medium', 'Human-readable label', $passed, 'Detail shown on failure');
```

Arguments: category (`seo`, `performance`, `security`, `crawlability`), a stable id, importance (`high` = 10 points, `medium` = 5, `low` = 2), a label, whether it passed, and a failure hint.

## Add an endpoint

1. Write a static method on `SeoLoop\Handlers` that returns `['text' => string, 'data' => array]`.
2. Add it to `Handlers::ENDPOINTS` with a one-line description.
3. Add it to the table in `README.md` and `README.txt`.

## Pull requests

- One change per PR, with a short description of what and why.
- Match the existing style (strict types, small methods, no comments that restate the code).
- Mention anything that changes output, since scripts depend on it.

## Ideas welcome

`/cwv` (Core Web Vitals), `/links` (broken links), `/schema` (JSON-LD validation), `/hreflang`, an MCP server wrapper, a GitHub Action for the CI gate.
