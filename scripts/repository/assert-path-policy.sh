#!/usr/bin/env bash
set -euo pipefail

legacy_windows_path='C:\!Projects\'
legacy_windows_path="${legacy_windows_path}college_portal"
legacy_worktrees='college'
legacy_worktrees="${legacy_worktrees}_portal-worktrees"
legacy_tmp='college'
legacy_tmp="${legacy_tmp}_portal\\tmp"

message="Использование устаревшего пути запрещено. Используйте C:\!Projects\CollegePortal."

failures=0
for forbidden in "$legacy_windows_path" "$legacy_worktrees" "$legacy_tmp"; do
  if git grep -n -I -F "$forbidden" -- .; then
    failures=1
  fi
done

if [ "$failures" -ne 0 ]; then
  echo "$message" >&2
  exit 1
fi

echo "[OK] CollegePortal path policy: no forbidden legacy Windows paths found."
