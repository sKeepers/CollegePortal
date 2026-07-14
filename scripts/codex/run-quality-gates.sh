#!/usr/bin/env bash
set -Eeuo pipefail

root=$(git rev-parse --show-toplevel)
cd "${root}"
git diff --check
scripts/security/check-forbidden-files.sh

if command -v docker >/dev/null && docker compose ps --services --status running | grep -qx backend; then
  docker compose exec -T backend php artisan test
else
  echo '[WARN] backend container не запущен; backend tests пропущены.' >&2
fi

if command -v docker >/dev/null && docker compose ps --services --status running | grep -qx frontend; then
  docker compose exec -T frontend npm run build
else
  echo '[WARN] frontend container не запущен; frontend build пропущен.' >&2
fi

if command -v gitleaks >/dev/null; then
  gitleaks detect --source "${root}" --redact --no-banner --exit-code 1
else
  echo '[WARN] gitleaks не установлен; используйте CI secret scan.' >&2
fi
