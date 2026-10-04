SEO Loop - SEO checks for your terminal. Free, no signup.

  curl seoloop.in/audit/example.com        full audit, score + grade
  curl seoloop.in/indexable/example.com    can search engines index it? yes/no + reasons
  curl seoloop.in/links/example.com        broken-link check (first 30 links)
  curl seoloop.in/schema/example.com       JSON-LD types and missing properties
  curl seoloop.in/status/example.com       HTTP status after redirects
  curl seoloop.in/title/example.com        page title
  curl seoloop.in/meta/example.com         title, description, canonical, OG, headings
  curl seoloop.in/headers/example.com      response headers
  curl seoloop.in/redirects/example.com    redirect chain
  curl seoloop.in/ttfb/example.com         time to first byte
  curl seoloop.in/ssl/example.com          TLS certificate and expiry
  curl seoloop.in/robots/example.com       robots.txt analysis
  curl seoloop.in/sitemap/example.com      sitemap discovery + URL count
  curl seoloop.in/ip                       your IP

JSON:    add ?json, send "Accept: application/json", or use /v1/<endpoint>/<target>
One key: curl 'seoloop.in/ssl/example.com?field=days_left'
CI gate: curl -fs 'seoloop.in/audit/example.com?min=80' >/dev/null   (HTTP 412 below 80)
Colour:  curl 'seoloop.in/audit/example.com?color'
Filter:  curl 'seoloop.in/audit/example.com?only=seo,security&skip=hsts'
Format:  curl 'seoloop.in/audit/example.com?format=csv'   (csv or md)
Docs:    seoloop.in/openapi.json   seoloop.in/llms.txt

Install the CLI:  curl -fsSL seoloop.in/install | sh      then:  seoloop audit example.com
Open source (MIT): https://github.com/mehranshahmiri/seoloop
