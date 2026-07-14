#!/usr/bin/env bash
set -Eeuo pipefail

task_id=${1:-}
branch=${2:-}
base=${3:-origin/develop}
root=${WORKTREE_ROOT:-/srv/college-worktrees}

[[ -n "${task_id}" ]] || { echo 'Использование: create-task-worktree.sh TASK-ID [branch] [base]' >&2; exit 2; }
[[ "${task_id}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'TASK-ID содержит недопустимые символы.' >&2; exit 2; }
command -v git >/dev/null || { echo 'Git не найден.' >&2; exit 1; }

repository=$(git rev-parse --show-toplevel)
[[ -z "$(git -C "${repository}" status --porcelain)" ]] || { echo 'Текущий worktree содержит изменения.' >&2; exit 1; }

branch=${branch:-feature/${task_id,,}}
target="${root}/${task_id,,}"
git -C "${repository}" fetch --all --prune
git -C "${repository}" rev-parse --verify "${base}" >/dev/null
[[ ! -e "${target}" ]] || { echo "Путь уже существует: ${target}" >&2; exit 1; }
mkdir -p "${root}"

if git -C "${repository}" show-ref --verify --quiet "refs/heads/${branch}"; then
  git -C "${repository}" worktree add "${target}" "${branch}"
else
  git -C "${repository}" worktree add -b "${branch}" "${target}" "${base}"
fi

printf 'Worktree: %s\nBranch:   %s\nHEAD:     %s\n' "${target}" "${branch}" "$(git -C "${target}" rev-parse HEAD)"
