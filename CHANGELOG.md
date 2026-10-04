# Changelog

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
