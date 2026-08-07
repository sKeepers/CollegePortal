# Changelog

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)
- [Documentation Report](REPORT.md)

## 0.8.0-rc4 - Private Release Candidate

### Fixed

- The release frontend image no longer reports itself as `dev-unknown` in the `development` environment. `npm run build` fires the `prebuild` hook, which regenerates `version.json` from git, but the image has neither git nor build metadata, so it overwrote the values recorded by `scripts/release/build-release.sh`. Found on 0.8.0-rc3 immediately after deployment.

## 0.8.0-rc3 - Private Release Candidate

First trunk release since 0.8.0-rc2. Merges 121 commits of UAT stabilisation that had never reached `develop`, and is the first release verified by CI on PostgreSQL 17 as well as SQLite.

### Added

- CI runs on feature branches and verifies the backend against PostgreSQL 17, not only in-memory SQLite.
- Planning document for login providers, password recovery and the mobile contour: `docs/AUTH_AND_MOBILE_PLAN.md`.

### Fixed

- Gate QR is accepted when a USB HID scanner types the token with a Russian keyboard layout active.
- Employee import no longer creates duplicates when the personnel number is empty.
- A teacher account without a linked Teacher profile sees an empty teaching load instead of a permission error.
- The UAT student is linked to a Person and Student profile, so the student cabinet, personal QR pass and group journal resolve.

## 0.8.0-rc2 - Private Release Candidate

### Added

- Installer lifecycle for Ubuntu Server: install, update, backup, restore, check and uninstall.
- UAT validation on a clean server documented in `docs/INSTALLATION_ACCEPTANCE_TEST.md`.
- Person foundation, Identity domain and digital passes.
- Admissions, FIS import, applicant documents and bulk operations.
- HR employee foundation, absence calendar and teacher replacements.
- Curriculum Engine, Teaching Load Engine and Schedule Engine with visual editor.
- Schedule-linked electronic journal and teacher journal workspace.
- Attendance analytics and access reports.
- RBAC permission matrix, audit log, settings center and reference data platform.
- UAT Center for role-based testing and feedback.

### Changed

- Release archive generation writes correct `version.json` metadata.
- Installer handles Ubuntu Docker Compose package differences.
- Restore resets schema before import to avoid duplicate/relation conflicts.
- Update recreates nginx after service rebuilds and waits for health.


### Documentation

- README.md is now the primary Russian GitHub entrypoint.
- Added README.en.md for future English publication.
- Localized GitHub issue and pull request templates.
- Added Russian security, support and vision materials for private RC/UAT.

### Security

- Private runtime data, credentials, certificates, backups, dumps and release archives are excluded from Git.
- Documentation records personal data handling and repository safety rules.

## Earlier Milestones

See `ROADMAP.md`, `TASKS.md` and module documentation in `docs/` for detailed task history.
