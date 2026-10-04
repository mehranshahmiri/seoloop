# Changelog

## Unreleased
- Docs link to the Marketplace listing of the Action

## 1.2.1
- The GitHub Action moved to its own repo, [mehranshahmiri/seoloop-action](https://github.com/mehranshahmiri/seoloop-action) (for the Marketplace). Use `uses: mehranshahmiri/seoloop-action@v1`. The old `mehranshahmiri/seoloop@v1` path keeps working at the `v1.2.0` tag only.
- Weekly self-audit workflow that runs the Action against seoloop.in

## 1.2.0
- New endpoint: `/compare/<a>?vs=<b>`
- MCP server at `/mcp` (Streamable HTTP, stateless): every endpoint is a tool
- CLI: `seoloop compare a.com b.com`, `seoloop audit x --diff` (what changed since the last run)
- `/docs` reference page; homepage covers compare, MCP, GitHub Action and `--diff`
- Shared stylesheet; `Runner` cache layer shared by the HTTP router and MCP
- Self-hosters: add the `seomcp` zone from `deploy/nginx-limits.conf` and the `/mcp` + `/compare/` locations from `deploy/nginx-site.conf`

## 1.1.1
- Fix: `/schema` text output no longer runs long type names into the status

## 1.1.0
- New endpoints: `/indexable`, `/links`, `/schema`
- `/audit`: `?only=` / `?skip=` filters, `?format=csv|md`
- `/openapi.json` and `/llms.txt`
- CLI: `--only`, `--skip`, `--format`, and the three new commands
- GitHub Action (`action.yml`), repo CI, Dockerfile and docker-compose
- Fix: word count no longer glues adjacent elements together or counts `<title>`
- Fix: plain-HTTP `curl seoloop.in/...` is answered directly instead of a 301

## 1.0.0
- Initial release: `/audit`, `/status`, `/title`, `/meta`, `/headers`, `/redirects`, `/ttfb`, `/ssl`, `/robots`, `/sitemap`
- SSRF-hardened fetcher, shell CLI and installer, nginx / PHP-FPM examples
