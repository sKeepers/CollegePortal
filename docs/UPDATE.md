# Update

Updates are performed with `installer/update.sh` against an existing `/opt/college-portal` installation.

```bash
sudo /opt/college-portal/installer/update.sh /path/to/college-portal-0.8.0-rc6.tar.gz
```

The update script:

1. creates a backup first;
2. enables Laravel maintenance mode where possible;
3. replaces application files while preserving `.env`, certificates and runtime storage;
4. rebuilds/restarts containers;
5. runs migrations;
6. runs `installer/check.sh`;
7. offers rollback if health checks fail.

Never update without a verified backup.
