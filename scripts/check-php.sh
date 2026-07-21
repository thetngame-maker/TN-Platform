#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if ! command -v php >/dev/null 2>&1; then
  echo "php not installed; skipping PHP syntax checks"
  exit 0
fi
find "$ROOT/experiences/wordpress" -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done
echo "PHP syntax checks passed"
