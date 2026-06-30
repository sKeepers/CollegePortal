#!/usr/bin/env bash
set -euo pipefail

DEV_DIR="/srv/college-dev"
PROD_DIR="/home/andale/college_portal"
BACKUP_ROOT="/srv/backups/college-portal"
CONFIRM_TEXT="DEPLOY_TO_PROD"

log() {
  printf '[deploy-to-prod] %s\n' "$1"
}

fail() {
  printf '[deploy-to-prod] ERROR: %s\n' "$1" >&2
  exit 1
}

require_command() {
  local command_name="$1"
  command -v "$command_name" >/dev/null 2>&1 || fail "Command not found: $command_name"
}

latest_backup() {
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort | tail -n 1
}

[ -d "$DEV_DIR" ] || fail "DEV directory not found: $DEV_DIR"
[ -d "$PROD_DIR" ] || fail "PROD directory not found: $PROD_DIR"
require_command docker
require_command rsync
require_command find

backup_dir="$(latest_backup)"
[ -n "$backup_dir" ] || fail "No backup found in $BACKUP_ROOT. Run scripts/deploy/backup-prod.sh first."
[ -f "$backup_dir/prod-files.tgz" ] || fail "Backup archive missing: $backup_dir/prod-files.tgz"
[ -f "$backup_dir/prod-db.sql" ] || fail "Database dump missing: $backup_dir/prod-db.sql"

cat <<PLAN
[deploy-to-prod] Plan:
  DEV source: $DEV_DIR
  PROD target: $PROD_DIR
  Backup used: $backup_dir

Actions after confirmation:
  1. Re-check DEV with scripts/deploy/check-dev.sh
  2. Sync DEV files to PROD with rsync without --delete
  3. Preserve environment files and runtime data
  4. Run composer install in PROD backend container if needed
  5. Run Laravel migrations in PROD backend container
  6. Run frontend build in PROD frontend container

Excluded from sync:
  .env
  backend/.env
  frontend/.env
  frontend/node_modules/
  backend/vendor/
  backend/storage/
  tmp/
  .git/

This script changes PROD only after exact confirmation.
PLAN

printf 'Type %s to continue: ' "$CONFIRM_TEXT"
read -r confirmation
[ "$confirmation" = "$CONFIRM_TEXT" ] || fail "Confirmation mismatch. Deployment cancelled."

log "Running DEV checks before deployment"
"$DEV_DIR/scripts/deploy/check-dev.sh"

log "Syncing files to PROD without deleting PROD-only files"
rsync -a \
  --exclude='.env' \
  --exclude='backend/.env' \
  --exclude='frontend/.env' \
  --exclude='frontend/node_modules/' \
  --exclude='backend/vendor/' \
  --exclude='backend/storage/' \
  --exclude='tmp/' \
  --exclude='.git/' \
  "$DEV_DIR/" "$PROD_DIR/"

log "Running PROD maintenance commands"
cd "$PROD_DIR"
docker compose exec -T backend composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose exec -T backend php artisan migrate --force
docker compose exec -T frontend npm run build

log "Deployment finished. Verify PROD manually."
