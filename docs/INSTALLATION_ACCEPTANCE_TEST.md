# Installation Acceptance Test - CollegePortal 0.8.0-rc2

Date: 2026-07-12
Status: PASS WITH WARNINGS

## Scope

Validated the autonomous CollegePortal release installer on a separate Ubuntu UAT server using the release archive, not the DEV working copy.

Lifecycle covered:

install -> health -> API smoke -> backup -> restore -> update -> rollback -> repeated install protection -> HTTPS smoke -> uninstall -> reinstall.

PROD was not touched.

## UAT Server

- Hostname: `srv-portal`
- IP: `192.168.34.17`
- SSH user: `andale`
- OS: Ubuntu 24.04.3 LTS
- Kernel: `6.8.0-134-generic`
- Architecture: `x86_64`
- CPU: 4 vCPU, Intel Xeon Gold 6226R under Microsoft Hyper-V
- RAM: 15 GiB
- Root disk: 98G ext4, about 83G free after tests
- SSH ED25519 fingerprint: `SHA256:1Itb3cVzunlqN2nUBa3/vBv9O1zypsDvlud++LBJ9J4`

## Release Artifact

Original requested artifact:

- `/srv/college-dev/releases/college-portal-0.8.0-rc1.tar.gz`
- SHA-256: `ed6aa78aafbf53d76b3a1ca50ae8f142be87b3fbf71b8624b61387c1180717f8`

The rc1 artifact was verified but failed acceptance due installer defects. Fixed release used for final acceptance:

- `/srv/college-dev/releases/college-portal-0.8.0-rc2.tar.gz`
- SHA-256: `17c360bc88043ad28bb2c5adea7020497affd422788fe94eb7c7326959fca611`
- Manifest commit: `ed499f4`
- Version: `0.8.0-rc2`
- Release: `Release 0.8 RC2`

## URLs

- HTTP: `http://192.168.34.17`
- HTTPS: `https://192.168.34.17`

HTTPS uses a local self-signed certificate with `CN=192.168.34.17`. This is acceptable for UAT/mobile scanner testing after installing trust manually, but it is not a public PROD certificate.

## Credentials

Admin email:

```text
<LOGIN>
```

Admin password is not stored in Git or this document. On the UAT server it is stored only in:

```text
<PRIVATE_ADMIN_CREDENTIALS_FILE>
```

The file must remain `0600`. Rotate the admin password before any wider pilot.

## Acceptance Results

| Check | Result | Evidence |
| --- | --- | --- |
| Clean install | PASS | Final reinstall completed in 49 seconds. |
| Health check | PASS | `installer/check.sh` passed database, storage, queue, live, ready, version, disk and backup checks. |
| HTTP routes | PASS | `/`, `/dashboard`, `/students`, `/groups`, `/teachers`, `/people`, `/admissions`, `/curricula`, `/teaching-load`, `/schedule`, `/journal`, `/attendance`, `/hr/employees`, `/hr/calendar`, `/admin/users`, `/admin/roles`, `/admin/permissions`, `/admin/settings`, `/admin/reference`, `/admin/audit`, `/admin/import`, `/admin/uat`, `/legacy` returned 200. |
| HTTPS smoke | PASS WITH WARNING | HTTPS routes returned 200 with self-signed certificate. |
| API login | PASS | Login with protected admin credentials succeeded after final reinstall credential correction. |
| API smoke | PASS | Created department, position, employee, teacher, subject, classroom, specialty, program, curriculum, curriculum subject, group, student, teaching load, schedule entry, journal lesson and digital identity. |
| Schedule Engine | PASS | Preview returned `can_apply=true`, apply created schedule entry. |
| Journal Engine | PASS | Lesson opened from schedule, completed and signed. |
| QR | PASS | Digital identity issued; QR SVG returned 200 and starts with SVG. |
| Dashboard | PASS | Executive dashboard endpoint returned OK. |
| Audit | PASS | Audit API returned OK. |
| Backup | PASS | Backups created under `/var/backups/college-portal/`, latest before uninstall: `/var/backups/college-portal/20260712-161853`. |
| Restore | PASS | Restore dropped/recreated schema, restored DB/storage/env and health remained OK. |
| Rollback | PASS | Rollback via `restore.sh` from `/var/backups/college-portal/20260712-161420`; test mutation was removed. |
| Update guard | PASS | Same-version update refused without `FORCE_SAME_VERSION_UPDATE=1`. |
| Forced repair update | PASS | Forced update completed in 54 seconds and `version.json` moved to build `ed499f4`. |
| Repeated install protection | PASS WITH WARNING | Running install while app is active exits before modification because port 80 is occupied. Message is port-based rather than install-state-based. |
| Uninstall | PASS | `uninstall.sh` removed `/opt/college-portal` and Docker volumes; backups preserved. |
| Reinstall | PASS | Reinstall completed in 49 seconds and health checks passed. |

## Defects Found And Fixed

1. Docker package name mismatch on Ubuntu 24.04: `docker-compose-plugin` was unavailable, while `docker-compose-v2` was available. `install.sh` now supports package fallbacks.
2. Generated PostgreSQL password was written only to `POSTGRES_PASSWORD`; backend `DB_PASSWORD` retained the placeholder value `<SECRET>` before the installer fix. `install.sh` now updates both.
3. Release backend missed PHP Redis extension. `backend/Dockerfile.release` now installs and enables `redis`.
4. Nginx routed `/health` and `/api` to frontend, causing 404. Release nginx config now routes these paths to PHP-FPM.
5. Release archive contained stale `frontend/public/version.json`. Release builder now writes version metadata into the archive.
6. `restore.sh` restored over an existing schema, causing relation/duplicate errors. It now drops and recreates `public` before import.
7. `update.sh` parsed backup output incorrectly and left nginx with stale upstream IPs after container recreation. It now parses backup path correctly, recreates nginx and waits for ready health.

## Warnings

- `docker compose` prints: `Docker Compose is configured to build using Bake, but buildx isn't installed`. Build still succeeds using the default builder.
- The UAT SSH account still uses password login for this test. Move to SSH keys and rotate the password.
- HTTPS is self-signed/local. For PROD use a real certificate behind a reverse proxy or approved internal CA.
- The uninstall script backup prompt should not be piped together with the destructive confirmation when backup is requested, because the nested backup process may consume stdin. For automation, run backup separately, then uninstall.

## Release Decision

Decision: PASS WITH WARNINGS.

CollegePortal 0.8.0-rc2 installer is acceptable for controlled UAT on a clean Ubuntu server. Before PROD-like deployment, address SSH hardening, certificate trust, and the repeated-install UX message.
