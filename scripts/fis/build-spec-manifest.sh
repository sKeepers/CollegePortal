#!/usr/bin/env bash
set -euo pipefail

SPEC_DIR="${FIS_SPEC_DIR:-backend/storage/app/private/fis-specs/4.9}"
MANIFEST="$SPEC_DIR/manifest.json"
SOURCE_URL="${FIS_OFFICIAL_INSTRUCTIONS_URL:-https://priem.rustest.ru/instructions}"
VERSION="${FIS_SPEC_VERSION:-4.9}"
GENERATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

if [ ! -d "$SPEC_DIR" ]; then
  echo "Spec directory not found: $SPEC_DIR" >&2
  exit 1
fi

files_json=""
first=1
while IFS= read -r -d '' file; do
  name="${file#$SPEC_DIR/}"
  [ "$name" = "manifest.json" ] && continue
  sha="$(sha256sum "$file" | awk '{print $1}')"
  size="$(wc -c < "$file")"
  comma=","; [ "$first" = 1 ] && comma="" && first=0
  files_json+="$comma
    {\"name\": \"$name\", \"sha256\": \"$sha\", \"size_bytes\": $size}"
done < <(find "$SPEC_DIR" -type f -print0 | sort -z)

cat > "$MANIFEST" <<JSON
{
  "source": "ФИС ГИА и Приема - официальные материалы ФЦТ",
  "version": "$VERSION",
  "url": "$SOURCE_URL",
  "generated_at": "$GENERATED_AT",
  "status": "local-files",
  "files": [$files_json
  ],
  "notes": ["Files are stored in private storage and are not committed to Git."]
}
JSON
cat "$MANIFEST"
