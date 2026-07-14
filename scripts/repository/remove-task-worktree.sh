#!/usr/bin/env bash
set -Eeuo pipefail

target=${1:-}
[[ -n "${target}" ]] || { echo 'Использование: remove-task-worktree.sh /path/to/worktree' >&2; exit 2; }
target=$(cd "${target}" && pwd)
repository=$(git rev-parse --show-toplevel)
mapfile -t worktrees < <(git -C "${repository}" worktree list --porcelain | sed -n 's/^worktree //p')
[[ ${#worktrees[@]} -gt 1 ]] || { echo 'Связанные worktree не найдены.' >&2; exit 1; }
[[ "${target}" != "${worktrees[0]}" ]] || { echo 'Удаление основного worktree запрещено.' >&2; exit 1; }
printf '%s\n' "${worktrees[@]}" | grep -Fx -- "${target}" >/dev/null || { echo 'Путь не зарегистрирован как worktree.' >&2; exit 1; }
[[ -z "$(git -C "${target}" status --porcelain)" ]] || { echo 'Worktree содержит изменения.' >&2; exit 1; }

git -C "${repository}" worktree remove "${target}"
printf 'Worktree удален: %s\nВетка сохранена.\n' "${target}"
