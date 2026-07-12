# Governance

CollegePortal is managed as a private internal project during RC/UAT.

## Decision Areas

- Architecture: documented in `docs/ARCHITECTURE_DOCUMENTATION.md` and domain documents.
- Security and access: governed by `docs/RBAC.md`, `docs/AUDIT_LOG.md` and `SECURITY.md`.
- Operations: governed by installer, deployment and backup/restore documentation.
- Product direction: tracked in `ROADMAP.md`, `TASKS.md` and milestone reviews.

## Roles

- Maintainers approve repository settings, release candidates and production readiness.
- Developers work through branches, commits and pull requests.
- UAT participants report findings through UAT Center or private issues.
- Administrators manage deployments and credentials outside Git.

## Release Policy

Release candidates are private until a separate publication decision is made. A release must have:

- clean working tree;
- passing backend tests and frontend build;
- secret scan review;
- release notes;
- documented rollback path;
- accepted security limitations.
