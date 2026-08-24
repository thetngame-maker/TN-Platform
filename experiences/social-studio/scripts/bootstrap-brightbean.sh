#!/usr/bin/env bash
set -euo pipefail

UPSTREAM_REPO="https://github.com/brightbeanxyz/brightbean-studio.git"
UPSTREAM_SHA="8fb037e07ef0da2cea4c857c9aeeb33a0c9db93e"
TARGET_DIR="${1:-./brightbean-studio}"

if [ -e "$TARGET_DIR" ]; then
  echo "Refusing to overwrite existing path: $TARGET_DIR" >&2
  exit 1
fi

echo "Cloning BrightBean Studio..."
git clone "$UPSTREAM_REPO" "$TARGET_DIR"
cd "$TARGET_DIR"

echo "Pinning upstream revision: $UPSTREAM_SHA"
git checkout --detach "$UPSTREAM_SHA"

if [ ! -f .env ]; then
  cp .env.example .env
fi

cat <<'MSG'

BrightBean Studio is ready locally at the pinned TN Social Studio revision.

Next steps:
  1. Edit .env with production-safe values.
  2. For local testing: docker compose up -d --build
  3. Create the first admin:
       docker compose exec app python manage.py createsuperuser
  4. Open http://localhost:8000

Production target:
  https://studio.thetngame.com

Do not commit .env or any social-platform credentials.
MSG
