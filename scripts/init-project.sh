#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$PROJECT_ROOT"

if [[ -f .env.example && ! -f .env ]]; then
    cp .env.example .env
fi

"$PROJECT_ROOT/scripts/init-laravel.sh"
"$PROJECT_ROOT/scripts/init-frontend.sh"

docker compose build

echo "Project scaffold is ready."
echo "Run: docker compose up -d"
