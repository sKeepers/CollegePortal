# Installer Lifecycle Tools

The `installer/` directory contains production lifecycle tools for a clean Ubuntu Server deployment:

- `install.sh` installs a new system into `/opt/college-portal`.
- `update.sh` updates from a release archive with backup-first behavior.
- `backup.sh` creates PostgreSQL/storage/config backups.
- `restore.sh` restores a verified backup after explicit confirmation.
- `uninstall.sh` removes the installed app after explicit confirmation.
- `check.sh` verifies containers, DB, storage, health endpoints, version and backups.

The scripts are designed for packaged releases, not for modifying the DEV checkout directly.
