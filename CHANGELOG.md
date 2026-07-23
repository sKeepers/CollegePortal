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
