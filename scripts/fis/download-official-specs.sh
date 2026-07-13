#!/usr/bin/env bash
set -euo pipefail

SOURCE_URL="${FIS_OFFICIAL_INSTRUCTIONS_URL:-https://priem.rustest.ru/instructions}"
TARGET_DIR="${FIS_SPEC_TARGET_DIR:-backend/storage/app/private/reference/fis}"
MANIFEST="$TARGET_DIR/manifest.json"
FETCHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
mkdir -p "$TARGET_DIR"

json_escape() {
  printf '%s' "$1" | tr '\n\r' '  ' | sed 's/\\/\\\\/g; s/"/\\"/g; s/	/ /g'
}

TMP_BODY="$TARGET_DIR/instructions.html.tmp"
HTTP_CODE="000"
ERROR=""
if HTTP_CODE=$(curl -fsSL -w '%{http_code}' -o "$TMP_BODY" "$SOURCE_URL" 2>"$TARGET_DIR/download.err"); then
  :
else
  ERROR="$(cat "$TARGET_DIR/download.err" 2>/dev/null || true)"
fi

SHA=""
SIZE=0
if [ -s "$TMP_BODY" ]; then
  SHA="$(sha256sum "$TMP_BODY" | awk '{print $1}')"
  SIZE="$(wc -c < "$TMP_BODY")"
  mv "$TMP_BODY" "$TARGET_DIR/instructions.html"
else
  rm -f "$TMP_BODY"
fi

if [ -n "$SHA" ]; then
  cat > "$MANIFEST" <<JSON
{
  "source": "ФИС ГИА и Приема - Инструкции",
  "url": "$(json_escape "$SOURCE_URL")",
  "fetched_at": "$FETCHED_AT",
  "http_code": "$(json_escape "$HTTP_CODE")",
  "status": "downloaded",
  "files": [
    {
      "name": "instructions.html",
      "url": "$(json_escape "$SOURCE_URL")",
      "sha256": "$SHA",
      "size_bytes": $SIZE,
      "fetched_at": "$FETCHED_AT"
    }
  ],
  "notes": []
}
JSON
else
  cat > "$MANIFEST" <<JSON
{
  "source": "ФИС ГИА и Приема - Инструкции",
  "url": "$(json_escape "$SOURCE_URL")",
  "fetched_at": "$FETCHED_AT",
  "http_code": "$(json_escape "$HTTP_CODE")",
  "status": "unavailable",
  "files": [],
  "notes": ["$(json_escape "${ERROR:-No body downloaded. The official SPA or network may require browser/VPN access.}")"]
}
JSON
fi

cat "$MANIFEST"
