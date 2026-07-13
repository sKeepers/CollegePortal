#!/usr/bin/env bash
set -euo pipefail

SRC_DIR="${1:-/mnt/data}"
TARGET_DIR="${FIS_DISCOVERED_SPEC_DIR:-backend/storage/app/private/fis-specs/discovered}"
mkdir -p "$TARGET_DIR"

if [ ! -d "$SRC_DIR" ]; then
  echo "Source directory not found: $SRC_DIR" >&2
  exit 1
fi

classify() {
  local file="$1"
  if grep -qi "discovery-ref\|contractRef\|disco" "$file"; then echo "import-service.disco.xml"; return; fi
  if grep -qi "http://schemas.microsoft.com/2003/10/Serialization" "$file"; then echo "microsoft-serialization.xsd"; return; fi
  if grep -qi "xs:any" "$file" && grep -qi "processContents=.lax." "$file"; then echo "import-service-wrapper.xsd"; return; fi
  if grep -qi "wsdl:definitions\|<definitions" "$file"; then
    if grep -qi "wsdl:service\|<service" "$file"; then echo "import-service.single.wsdl"; else echo "import-service.wsdl.xml"; fi
    return
  fi
  echo "unknown-$(basename "$file")"
}

manifest="$TARGET_DIR/manifest.json"
obtained_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf '{\n  "obtained_at": "%s",\n  "classification": "FIS TEST WSDL/XSD/DISCO discovered via ViPNet workstation",\n  "files": [\n' "$obtained_at" > "$manifest"
first=1
for file in "$SRC_DIR"/*.xml "$SRC_DIR"/*.wsdl "$SRC_DIR"/*.xsd; do
  [ -f "$file" ] || continue
  logical="$(classify "$file")"
  dest="$TARGET_DIR/$logical"
  cp "$file" "$dest"
  sha="$(sha256sum "$dest" | awk '{print $1}')"
  size="$(wc -c < "$dest" | tr -d ' ')"
  [ "$first" -eq 1 ] || printf ',\n' >> "$manifest"
  first=0
  printf '    {"logical_name":"%s","source_url":"manual-vipnet-download","content_type":"application/xml","size":%s,"sha256":"%s","storage_path":"backend/storage/app/private/fis-specs/discovered/%s"}' "$logical" "$size" "$sha" "$logical" >> "$manifest"
done
printf '\n  ]\n}\n' >> "$manifest"
echo "$manifest"
