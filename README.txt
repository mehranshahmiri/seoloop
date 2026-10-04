SEO Loop - SEO checks for your terminal. Free, no signup.

  curl seoloop.in/audit/example.com        full audit, score + grade
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

Install the CLI:  curl -fsSL seoloop.in/install | sh      then:  seoloop audit example.com
Open source (MIT): https://github.com/mehranshahmiri/seoloop
