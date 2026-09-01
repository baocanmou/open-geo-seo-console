#!/usr/bin/env bash
set -Eeuo pipefail

node scripts/check-ip-origin.mjs

fail=0

while IFS= read -r path; do
  case "$path" in
    .env|*.pem|*.key|*.p12|*.pfx|*.crt|*.csr|*.sql.gz|*.tar|*.tar.gz|*.zip|*.log|output/*|release/*)
      echo "Forbidden public-release path: $path" >&2
      fail=1
      ;;
  esac
done < <(git ls-files --cached --others --exclude-standard)

secret_pattern='-----BEGIN ([A-Z ]+ )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{35}|gh[pousr]_[0-9A-Za-z]{20,}|xox[baprs]-[0-9A-Za-z-]{20,}|Authorization:[[:space:]]*Bearer[[:space:]]+[0-9A-Za-z._-]{20,}'
if git grep -IEn -e "$secret_pattern" -- . ':(exclude)scripts/check-public-release.sh'; then
  echo "Possible credential material found in tracked files." >&2
  fail=1
fi

if git grep -IEn '/www/wwwroot/|/www/backup/|InstanceId|AccessKeyId' -- . ':(exclude)scripts/check-public-release.sh'; then
  echo "Possible private deployment detail found in tracked files." >&2
  fail=1
fi

if (( fail != 0 )); then
  exit 1
fi

echo "Public-release safety checks passed."
