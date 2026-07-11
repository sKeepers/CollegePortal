# System Requirements

CollegePortal production installer targets a clean Ubuntu Server VM.

## Supported OS

- Primary target: Ubuntu Server 24.04 LTS amd64.
- Compatible target: Ubuntu Server 22.04 LTS amd64, with additional manual verification.

Do not run the installer over the DEV checkout `/srv/college-dev` or over an existing PROD directory. Use a separate VM or a clean host.

## Minimum resources

- CPU: 2 vCPU minimum, 4 vCPU recommended.
- RAM: 4 GB minimum, 8 GB recommended.
- Disk: 40 GB minimum, 80 GB recommended for pilot data and backups.
- Network: fixed LAN IP or DNS name.

## Ports

- HTTP: 80 by default.
- HTTPS: 443 by default.
- PostgreSQL is internal to Docker and is not exposed to the host.

## Dependencies

The installer can install Docker Engine and Docker Compose plugin when they are missing. It requires root on the target VM.
