#!/usr/bin/env bash
set -Eeuo pipefail

root=$(git rev-parse --show-toplevel)
printf 'Host:         %s\n' "$(hostname)"
printf 'Repository:   %s\n' "${root}"
printf 'Branch:       %s\n' "$(git -C "${root}" branch --show-current)"
printf 'HEAD:         %s\n' "$(git -C "${root}" rev-parse HEAD)"
if [[ -z "$(git -C "${root}" status --porcelain)" ]]; then
  echo 'Working tree: clean'
else
  echo 'Working tree: dirty'
fi
"${root}/scripts/codex/setup-linux.sh"
