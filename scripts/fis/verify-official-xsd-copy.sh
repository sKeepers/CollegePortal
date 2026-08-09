#!/usr/bin/env bash
# Копия официальной XSD в backend/resources должна совпадать с оригиналом в docs
# байт в байт и с контрольной суммой из манифеста. Контейнер бэкенда видит
# только каталог backend/, поэтому без копии приложение схему не откроет, а
# разошедшаяся копия хуже отсутствующей: проверка будет идти по чужому файлу.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
original="$root/docs/external-services/ФИС ГИА и Приема/XSD схема метода импорта Сервиса автоматизированного взаимодействия.xsd"
copy="$root/backend/resources/fis/gia-priem/4.9/import-package.xsd"
manifest="$root/backend/resources/fis/gia-priem/4.9/manifest.json"

for file in "$original" "$copy" "$manifest"; do
  if [ ! -f "$file" ]; then
    echo "[FAIL] Файл не найден: $file" >&2
    exit 1
  fi
done

original_sha="$(sha256sum "$original" | awk '{print $1}')"
copy_sha="$(sha256sum "$copy" | awk '{print $1}')"
manifest_sha="$(grep -o '"sha256": "[0-9a-f]\{64\}"' "$manifest" | head -1 | cut -d'"' -f4)"

if [ "$original_sha" != "$copy_sha" ]; then
  echo "[FAIL] Копия XSD разошлась с оригиналом:" >&2
  echo "       docs:      $original_sha" >&2
  echo "       resources: $copy_sha" >&2
  exit 1
fi

if [ "$manifest_sha" != "$copy_sha" ]; then
  echo "[FAIL] В манифесте записана другая контрольная сумма:" >&2
  echo "       manifest:  $manifest_sha" >&2
  echo "       resources: $copy_sha" >&2
  exit 1
fi

echo "[OK] XSD 4.9 совпадает с оригиналом и манифестом: $copy_sha"
