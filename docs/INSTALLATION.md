# Installation

This document describes a clean installation from a release archive such as `college-portal-0.8.0-rc6.tar.gz`.

## Safety rules

- Install only on a separate VM or clean Ubuntu Server.
- Do not install over `/srv/college-dev`.
- Do not install over `/home/andale/college_portal` without a separate PROD migration approval.
- Do not commit generated `.env`, certificates, backups or database dumps.

## Steps

1. Copy the release archive to the target VM.
2. Unpack it to a temporary directory.
3. Run as root:

```bash
sudo bash installer/install.sh
```

4. Answer the prompts:
   - domain or IP;
   - HTTP/HTTPS mode;
   - college name;
   - first administrator email and password;
   - PostgreSQL password;
   - timezone;
   - whether demo data should be created.

Demo data is disabled by default and is created only after an explicit `yes`.

## HTTPS modes

- `http`: local or pilot HTTP mode.
- `existing-cert`: use a certificate already placed in `certs/`.
- `letsencrypt`: public DNS name only. Do not use it for private IP addresses.
- `self-signed`: pilot/dev only. Install the local CA on test devices before using camera features.

## Result

The installer creates `/opt/college-portal`, starts Docker containers, runs migrations, seeds roles/permissions/reference data, creates the first admin and checks `/health/ready`.


## FIS API Settings

The official FIS outbound connector is disabled by default. Configure only in DEV/TEST after official specs are loaded: `FIS_API_ENABLED`, `FIS_API_MODE`, `FIS_API_TEST_ENDPOINT`, `FIS_API_XSD_PATH`, `FIS_API_WSDL_PATH`. Keep credentials in secrets/secret mounts, not Git or frontend settings.
