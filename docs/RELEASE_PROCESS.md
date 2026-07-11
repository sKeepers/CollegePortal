# Release Process

Release artifacts are built from a clean Git state in `/srv/college-dev`.

```bash
scripts/release/build-release.sh
```

The script checks that the working tree is clean, runs backend tests and frontend build, creates `releases/college-portal-<version>.tar.gz`, writes a SHA-256 file and creates a manifest.

Release archives must exclude:

- `.env` files;
- real data and storage;
- node_modules and vendor when images install dependencies;
- DEV certificates;
- logs, temporary files, screenshots and backups.

The release archive is not published automatically.
