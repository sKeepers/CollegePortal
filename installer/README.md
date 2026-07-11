# CollegePortal Installer

This directory contains the production installation and lifecycle tools for CollegePortal.

Supported target:
- Ubuntu Server 24.04 LTS amd64
- Ubuntu Server 22.04 LTS amd64 is expected to work with the same Docker-based flow

Main scripts:
- `install.sh` clean installation on a new VM
- `update.sh` safe update flow with backup and health-check
- `backup.sh` PostgreSQL + storage + configuration backup
- `restore.sh` restore from a checked backup manifest
- `check.sh` lifecycle health diagnostics
- `uninstall.sh` guarded uninstall

Run scripts as root or through sudo. Never commit `.env`, certificates, backups, dumps or private storage.
