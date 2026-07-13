#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="${1:-/srv/college-dev}"
EXPECTED_REMOTE="github.com/sKeepers/CollegePortal"

if ! command -v git >/dev/null 2>&1; then
  echo "git is not installed" >&2
  exit 1
fi

cd "$REPO_DIR"
remote="$(git remote get-url origin 2>/dev/null || true)"
case "$remote" in
  *"$EXPECTED_REMOTE"*) ;;
  *) echo "Unexpected origin remote: $remote" >&2; exit 1 ;;
esac

if [ -n "$(git status --porcelain)" ]; then
  echo "Working tree is dirty. Refusing to pull." >&2
  git status --short
  exit 2
fi

printf 'Before: branch=%s head=%s remote=%s\n' "$(git branch --show-current)" "$(git rev-parse --short HEAD)" "$remote"
git fetch --all --prune
git checkout develop
git pull --ff-only origin develop
if git rev-parse --abbrev-ref --symbolic-full-name @{upstream} >/dev/null 2>&1; then
  git rev-list --left-right --count HEAD...@{upstream} | awk '{print "ahead="$1" behind="$2}'
else
  echo "ahead=unknown behind=unknown"
fi
printf 'After: branch=%s head=%s\n' "$(git branch --show-current)" "$(git rev-parse --short HEAD)"
git status --short
