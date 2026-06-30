#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="$PROJECT_ROOT/frontend"
SCAFFOLD_DIR="$PROJECT_ROOT/.scaffold"
VUE_TMP_DIR="$SCAFFOLD_DIR/vue"
FRONTEND_ENV_EXAMPLE="$FRONTEND_DIR/.env.example"
FRONTEND_ENV="$FRONTEND_DIR/.env"

if [[ -f "$FRONTEND_DIR/package.json" ]]; then
    echo "Vue/Vite already exists in frontend/. Nothing to do."
    exit 0
fi

docker --version >/dev/null

mkdir -p "$SCAFFOLD_DIR"
rm -rf "$VUE_TMP_DIR"

docker run --rm \
    --user "$(id -u):$(id -g)" \
    -e npm_config_cache=/tmp/npm \
    -v "$SCAFFOLD_DIR:/workspace" \
    -w /workspace \
    node:22-alpine \
    sh -lc "npm create vite@latest vue -- --template vue && cd vue && npm install pinia vue-router @vitejs/plugin-vue tailwindcss @tailwindcss/vite"

FRONTEND_ENV_EXAMPLE_BACKUP=""
if [[ -f "$FRONTEND_ENV_EXAMPLE" ]]; then
    FRONTEND_ENV_EXAMPLE_BACKUP="$(mktemp)"
    cp "$FRONTEND_ENV_EXAMPLE" "$FRONTEND_ENV_EXAMPLE_BACKUP"
fi

cp -a "$VUE_TMP_DIR"/. "$FRONTEND_DIR"/

if [[ -n "$FRONTEND_ENV_EXAMPLE_BACKUP" ]]; then
    cp "$FRONTEND_ENV_EXAMPLE_BACKUP" "$FRONTEND_ENV_EXAMPLE"
fi

if [[ -f "$FRONTEND_ENV_EXAMPLE" ]]; then
    cp "$FRONTEND_ENV_EXAMPLE" "$FRONTEND_ENV"
fi

echo "Vue/Vite scaffold is ready in frontend/."
