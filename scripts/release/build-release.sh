#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "${ROOT}"
source installer/VERSION
release_name="college-portal-${version}"
out_dir="${ROOT}/releases"
archive="${out_dir}/${release_name}.tar.gz"
checksum="${archive}.sha256"
manifest="${out_dir}/${release_name}.manifest.json"
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
[[ -z "$(git status --porcelain)" ]] || fail "Git working tree must be clean before building a release."
mkdir -p "${out_dir}"
printf '[INFO] Running backend tests on SQLite.\n'
docker compose exec -T backend php artisan test
# Второй прогон — на той же СУБД, на которой работает бой. Без него «сборка
# прошла зелёной» значит меньше, чем кажется: на SQLite не видно ни исчерпания
# блокировок PostgreSQL, ни разницы в типах, ни поведения последовательностей.
# Так `0.8.0-rc8` собрался поверх ствола, у которого прогон на PostgreSQL падал.
#
# База обязана быть отдельной и создаваться заново: `RefreshDatabase` начинает
# с `migrate:fresh`, и прогон, направленный на базу стенда, сотрёт стенд.
# Переопределение приходит переменной окружения контейнера — Dotenv не
# перекрывает уже заданное, поэтому `.env` тут ни при чём.
release_test_db="college_portal_release_test"
printf '[INFO] Running backend tests on PostgreSQL 17 (database %s).\n' "${release_test_db}"
docker compose exec -T postgres sh -c \
  "psql -U \$POSTGRES_USER -d postgres -q -v ON_ERROR_STOP=1 -c \"DROP DATABASE IF EXISTS ${release_test_db}\" -c \"CREATE DATABASE ${release_test_db}\""
docker compose exec -T -e DB_DATABASE="${release_test_db}" backend php artisan test -c phpunit.pgsql.xml
# Упавший прогон обрывает сборку раньше этой строки, и база остаётся — нарочно:
# по ней видно, на чём всё встало. Следующая сборка её пересоздаст.
docker compose exec -T postgres sh -c \
  "psql -U \$POSTGRES_USER -d postgres -q -v ON_ERROR_STOP=1 -c \"DROP DATABASE IF EXISTS ${release_test_db}\""
printf '[INFO] Running frontend build.\n'
docker compose exec -T frontend npm run build
commit=$(git rev-parse --short HEAD)
full_commit=$(git rev-parse HEAD)
build_date=$(date -Is)
tmp=$(mktemp -d)
trap 'rm -rf "${tmp}"' EXIT
git archive --format=tar HEAD | tar -x -C "${tmp}"
cat > "${tmp}/release-metadata.json" <<JSON
{
  "name": "${name}",
  "version": "${version}",
  "release": "${release}",
  "build": "${commit}",
  "gitCommit": "${full_commit}",
  "buildDate": "${build_date}"
}
JSON
cat > "${tmp}/frontend/public/version.json" <<JSON
{
  "name": "${name}",
  "version": "${version}",
  "release": "${release}",
  "build": "${commit}",
  "gitCommit": "${full_commit}",
  "buildDate": "${build_date}",
  "environment": "production",
  "frontendStack": "Vue 3 + Quasar + Vite",
  "backendStack": "Laravel 12 + PHP",
  "apiVersion": "v1"
}
JSON
rm -rf "${tmp}/.env" "${tmp}/backend/.env" "${tmp}/frontend/.env" "${tmp}/node_modules" "${tmp}/vendor" "${tmp}/frontend/dist" "${tmp}/tmp" "${tmp}/logs" "${tmp}/certs" "${tmp}/releases"
tar -czf "${archive}" -C "${tmp}" .
sha256sum "${archive}" > "${checksum}"
cat > "${manifest}" <<JSON
{
  "archive": "$(basename "${archive}")",
  "sha256": "$(cut -d' ' -f1 "${checksum}")",
  "version": "${version}",
  "commit": "${commit}",
  "buildDate": "${build_date}",
  "sizeBytes": $(stat -c%s "${archive}")
}
JSON
printf '[INFO] Release archive: %s\n' "${archive}"
printf '[INFO] SHA-256: %s\n' "$(cut -d' ' -f1 "${checksum}")"
printf '[INFO] Manifest: %s\n' "${manifest}"
