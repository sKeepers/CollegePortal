#!/usr/bin/env bash
set -euo pipefail

LARAVEL_VERSION="${1:-^12.0}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$PROJECT_ROOT/backend"
SCAFFOLD_DIR="$PROJECT_ROOT/.scaffold"
LARAVEL_TMP_DIR="$SCAFFOLD_DIR/laravel"
BACKEND_ENV_EXAMPLE="$BACKEND_DIR/.env.example"
BACKEND_ENV="$BACKEND_DIR/.env"
DOCKERFILE="$BACKEND_DIR/Dockerfile"

if [[ -f "$BACKEND_DIR/artisan" ]]; then
    echo "Laravel already exists in backend/. Nothing to do."
    exit 0
fi

docker --version >/dev/null

mkdir -p "$SCAFFOLD_DIR"
rm -rf "$LARAVEL_TMP_DIR"

docker run --rm \
    --user "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$SCAFFOLD_DIR:/workspace" \
    -w /workspace \
    composer:2 \
    sh -lc "composer create-project laravel/laravel laravel '$LARAVEL_VERSION' --prefer-dist"

DOCKERFILE_BACKUP=""
BACKEND_ENV_EXAMPLE_BACKUP=""
if [[ -f "$DOCKERFILE" ]]; then
    DOCKERFILE_BACKUP="$(mktemp)"
    cp "$DOCKERFILE" "$DOCKERFILE_BACKUP"
fi
if [[ -f "$BACKEND_ENV_EXAMPLE" ]]; then
    BACKEND_ENV_EXAMPLE_BACKUP="$(mktemp)"
    cp "$BACKEND_ENV_EXAMPLE" "$BACKEND_ENV_EXAMPLE_BACKUP"
fi

cp -a "$LARAVEL_TMP_DIR"/. "$BACKEND_DIR"/

if [[ -n "$DOCKERFILE_BACKUP" ]]; then
    cp "$DOCKERFILE_BACKUP" "$DOCKERFILE"
fi
if [[ -n "$BACKEND_ENV_EXAMPLE_BACKUP" ]]; then
    cp "$BACKEND_ENV_EXAMPLE_BACKUP" "$BACKEND_ENV_EXAMPLE"
fi

cp "$BACKEND_ENV_EXAMPLE" "$BACKEND_ENV"

docker compose run --rm backend php artisan key:generate
chmod -R a+rwX "$BACKEND_DIR/storage" "$BACKEND_DIR/bootstrap/cache"

echo "Laravel scaffold is ready in backend/."
