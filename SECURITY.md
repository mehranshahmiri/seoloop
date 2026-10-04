# Security policy

SEO Loop fetches URLs supplied by anonymous users, so the main risk is server-side request forgery (SSRF): getting the service to reach internal addresses. See "Security model" in the README for how `src/Fetcher.php` defends against it.

## Reporting a vulnerability

Please **do not open a public issue**. Use GitHub's private reporting instead:
**Security tab → Report a vulnerability** on https://github.com/mehranshahmiri/seoloop

Include what you did, what you expected, and what happened. You'll get a reply as soon as possible, and credit in the fix if you want it.

## In scope

- Any way to make the service connect to a private, loopback, link-local or otherwise non-public address
- Bypasses of the port, scheme, redirect or size limits
- Header or response injection, cache poisoning, path traversal

## Out of scope

- Rate-limit tuning (it is nginx configuration, see `deploy/`)
- Findings that need a modified self-hosted deployment
