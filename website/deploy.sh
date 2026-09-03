#!/bin/bash
# Deploy website/ to agentyllo.com httpdocs/ (preserves /downloads/).
set -e
NETRC="$1"
HOST="ftp://srv1.hostwebo.cloud/httpdocs"
find . -type f ! -path './node_modules/*' ! -name 'deploy.sh' | while read -r f; do
  rel="${f#./}"
  curl -sS --netrc-file "$NETRC" --ssl --ftp-create-dirs -T "$f" "$HOST/$rel" && echo "  up $rel"
done
