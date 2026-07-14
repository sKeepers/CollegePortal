#!/usr/bin/env bash
set -Eeuo pipefail

root=$(git rev-parse --show-toplevel)
cd "${root}"
failed=0

while IFS= read -r -d '' file; do
  lower=${file,,}
  case "${lower}" in
    *.env.example|frontend/.env.e2e.example|*/.gitignore)
      continue
      ;;
    .env|*/.env|*.env.local|*.env.production|*.env.development|*.pem|*.key|*.p12|*.pfx|*.crt|*.cer|*.sql|*.dump|*.backup|*.bak|*.xls)
      printf '[FORBIDDEN] %s\n' "${file}" >&2
      failed=1
      continue
      ;;
  esac

  case "${lower}" in
    *storage/app/private/*|*generated-documents/*|*backups/*|*dumps/*|*private-keys/*)
      printf '[FORBIDDEN] %s\n' "${file}" >&2
      failed=1
      ;;
    *.xlsx|*.csv)
      case "${lower}" in
        docs/external-services/*|docs/import-templates/*|backend/tests/fixtures/*|frontend/e2e/fixtures/*) ;;
        *) printf '[FORBIDDEN] %s\n' "${file}" >&2; failed=1 ;;
      esac
      ;;
    *.wsdl|*.xsd)
      case "${lower}" in
        backend/tests/fixtures/*) ;;
        docs/external-services/*) ;;
        *) printf '[REVIEW REQUIRED] %s\n' "${file}" >&2; failed=1 ;;
      esac
      ;;
  esac
done < <(git ls-files -z)

if [[ ${failed} -ne 0 ]]; then
  echo 'Forbidden-file check failed.' >&2
  exit 1
fi
echo 'Forbidden-file check passed.'
