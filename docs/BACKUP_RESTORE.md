# Backup and Restore

Backups are stored in `/var/backups/college-portal/<timestamp>/`.

## Backup

```bash
sudo /opt/college-portal/installer/backup.sh
```

The backup contains:

- PostgreSQL dump;
- private application storage archive;
- protected `.env` copy;
- manifest with version/date/files;
- SHA-256 checksums.

Backup directories are created with restrictive permissions.

## Restore

```bash
sudo /opt/college-portal/installer/restore.sh /var/backups/college-portal/<timestamp>
```

Restore requires the exact confirmation phrase `RESTORE COLLEGEPORTAL`, verifies checksums, stops application containers, restores DB/storage and then runs health checks.
