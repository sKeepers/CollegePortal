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

# Проверки и архив берутся из **разных источников**, и это не теория.
#
# Прогоны идут `docker compose exec -T backend`, то есть внутри контейнеров
# стенда, а у них `backend/` примонтирован из основного checkout. Архив же
# снимается `git archive HEAD` — из дерева, где запущен скрипт. Совпадают эти
# два источника ровно тогда, когда скрипт запущен в том же checkout, что
# смонтирован в контейнеры, и никто не влил туда за девять минут прогонов.
#
# 04.09.2026 разошлись оба условия, по разу каждое. Сначала слияние легло в
# фазе прогонов: архив уехал с тремя коммитами, которых эта сборка не
# проверяла ни разу, а выглядела зелёной. Потом сборку запустили из
# закреплённого worktree, чтобы дерево не двигалось, — и она упала на первой
# строке с `service "backend" is not running`, потому что compose там другой;
# не упади она, архив взялся бы из worktree, а прогоны прошли бы по основному
# checkout.
#
# Поэтому ниже два отказа: первый — до прогонов (чужое дерево ловится сразу),
# второй — перед снятием архива (уехавший коммит виден только там).

# 1. Проверки пойдут по тому же дереву, из которого снимется архив.
#
# Спрашиваем не имя каталога и не имя проекта compose, а сам контейнер: что у
# него примонтировано под `/var/www/html`. Имя совпадает и у чужого checkout,
# а точка монтирования — предмет.
backend_container=$(docker compose ps -q backend 2>/dev/null || true)
[[ -n "${backend_container}" ]] || fail "В этом дереве нет поднятого контейнера backend, а прогоны идут внутри него. Собирайте там, где подняты контейнеры стенда: у worktree свой проект compose, и сборка отсюда проверила бы одно дерево, а заархивировала другое."
mounted_backend=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}' "${backend_container}")
[[ "${mounted_backend}" == "${ROOT}/backend" ]] || fail "Прогоны пойдут по чужому дереву: контейнер держит ${mounted_backend}, а архив снимется с ${ROOT}/backend. Собирайте там же, где подняты контейнеры."

# 2. Запоминаем коммит: перед снятием архива он обязан быть тем же.
head_before=$(git rev-parse HEAD)

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
# Имя с коммитом, а не общее на все сборки: у двух сборок разом общее имя
# означает, что `RefreshDatabase` одной сносит базу другой, и вторая покажет
# падения, не имеющие отношения к коду. Сегодня это никого не задело только
# потому, что собирает один человек.
#
# Упавший прогон базу оставляет нарочно — по ней видно, на чём встало; с
# коммитом в имени видно ещё и на какой сборке.
release_test_db="college_portal_release_test_${head_before:0:9}"
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
# Дерево не уехало за время прогонов. Иначе архив снимется с одного коммита, а
# зелёными объявлены проверки другого — и отличить это потом нечем, кроме как
# сверив `gitCommit` архива с коммитом, с которого начинали.
[[ "$(git rev-parse HEAD)" == "${head_before}" ]] || fail "Дерево уехало во время сборки: начинали с ${head_before}, сейчас $(git rev-parse HEAD). Проверки относятся к прежнему коммиту — соберите заново."
[[ -z "$(git status --porcelain)" ]] || fail "Дерево испачкано во время сборки: проверки относятся к другому состоянию файлов."

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
