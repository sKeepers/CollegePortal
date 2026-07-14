#!/usr/bin/env bash
set -Eeuo pipefail

echo 'Проверка Linux DEV. Пакеты автоматически не устанавливаются.'
for tool in git docker php composer node npm bash; do
  if command -v "${tool}" >/dev/null; then
    printf '[OK]   %s -> %s\n' "${tool}" "$(command -v "${tool}")"
  else
    printf '[MISS] %s\n' "${tool}"
  fi
done
echo 'Для отсутствующих инструментов используйте утвержденный runbook DEV.'
