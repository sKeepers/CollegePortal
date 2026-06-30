#!/usr/bin/env bash
set -euo pipefail

PROD_DIR="/home/andale/college_portal"
PROD_DB_CONTAINER="college_portal_postgres"
PROD_DB_NAME="college_portal"
PROD_DB_USER="college_user"
CONFIRM_TEXT="ROLLBACK_PROD"
BACKUP_DIR="${1:-}"

log() {
  printf '[rollback-prod] %s\n' "$1"
}

fail() {
  printf '[rollback-prod] ERROR: %s\n' "$1" >&2
  exit 1
}

require_command() {
  local command_name="$1"
  command -v "$command_name" >/dev/null 2>&1 || fail "Command not found: $command_name"
}

[ -n "$BACKUP_DIR" ] || fail "Usage: scripts/deploy/rollback-prod.sh /srv/backups/college-portal/<timestamp>"
[ -d "$BACKUP_DIR" ] || fail "Backup directory not found: $BACKUP_DIR"
[ -f "$BACKUP_DIR/prod-files.tgz" ] || fail "Backup files archive missing: $BACKUP_DIR/prod-files.tgz"
[ -f "$BACKUP_DIR/prod-db.sql" ] || fail "Backup database dump missing: $BACKUP_DIR/prod-db.sql"
[ -d "$PROD_DIR" ] || fail "PROD directory not found: $PROD_DIR"
require_command docker
require_command tar

cat <<PLAN
[rollback-prod] Plan:
  PROD target: $PROD_DIR
  Backup source: $BACKUP_DIR

Actions after confirmation:
  1. Stop PROD containers
  2. Extract project files from backup into PROD directory
  3. Start PROD database
  4. Restore PostgreSQL dump into PROD database
  5. Start all PROD containers

This changes PROD and should be used only when rollback is required.
PLAN

printf 'Type %s to continue: ' "$CONFIRM_TEXT"
read -r confirmation
[ "$confirmation" = "$CONFIRM_TEXT" ] || fail "Confirmation mismatch. Rollback cancelled."

cd "$PROD_DIR"
log "Stopping PROD containers"
docker compose stop

log "Restoring files from backup"
tar -xzf "$BACKUP_DIR/prod-files.tgz" -C "$PROD_DIR"

log "Starting PROD database"
docker compose up -d postgres

log "Restoring PostgreSQL dump"
docker exec -i "$PROD_DB_CONTAINER" psql -U "$PROD_DB_USER" -d "$PROD_DB_NAME" < "$BACKUP_DIR/prod-db.sql"

log "Starting PROD containers"
docker compose up -d

log "Rollback finished. Verify PROD manually."
