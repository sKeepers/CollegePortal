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
printf '[INFO] Running backend tests.\n'
docker compose exec -T backend php artisan test
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
