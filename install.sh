#!/bin/sh
# Installs the `seoloop` CLI. Usage: curl -fsSL https://seoloop.in/install | sh
set -eu
BASE="${SEOLOOP_URL:-https://seoloop.in}"
command -v curl >/dev/null 2>&1 || { echo "seoloop install: curl is required" >&2; exit 1; }

if [ -w /usr/local/bin ]; then dir=/usr/local/bin; sudo=""
elif command -v sudo >/dev/null 2>&1 && [ "$(id -u)" != 0 ] && [ -t 0 ] 2>/dev/null; then dir=/usr/local/bin; sudo="sudo"
else dir="$HOME/.local/bin"; sudo=""; mkdir -p "$dir"
fi

tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
curl -fsSL "$BASE/seoloop" -o "$tmp"
head -n1 "$tmp" | grep -q '^#!/bin/sh' || { echo "seoloop install: unexpected download, aborting" >&2; exit 1; }
$sudo install -m 0755 "$tmp" "$dir/seoloop"
echo "Installed $dir/seoloop"
case ":$PATH:" in *":$dir:"*) ;; *) echo "Note: add $dir to your PATH" ;; esac
echo "Try:  seoloop audit example.com"
