#!/usr/bin/env sh
# Build the WordPress.org distribution zip for Agentyllo (and the companion).
# Usage: sh tools/build-zip.sh [version]
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(grep -m1 "Version:" "$ROOT/agentyllo/agentyllo.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')}"
OUT="$ROOT/dist"
mkdir -p "$OUT"
cd "$ROOT"
npm run build >/dev/null
rm -f "$OUT/agentyllo-$VERSION.zip" "$OUT/agentyllo-local-ai-$VERSION.zip"
# Core: exclude dev files (tests, vendor dev deps, sources of the JS build, dotfiles).
zip -rq "$OUT/agentyllo-$VERSION.zip" agentyllo \
  -x "agentyllo/tests/*" "agentyllo/vendor/*" "agentyllo/src-js/*" "agentyllo/node_modules/*" \
     "agentyllo/composer.json" "agentyllo/composer.lock" "agentyllo/phpunit.xml.dist" "agentyllo/.phpcs*" \
     "agentyllo/*.map" "agentyllo/.DS_Store" "agentyllo/*/.DS_Store"
zip -rq "$OUT/agentyllo-local-ai-$VERSION.zip" agentyllo-local-ai -x "agentyllo-local-ai/.DS_Store"
echo "Built:"
ls -la "$OUT"/*.zip
