# Project Status

## Project Documentation Map

- [Documentation Index](README.md)
- [Background Agents](AGENTS.md)
- [Roadmap](../ROADMAP.md)
- [Tasks](../TASKS.md)
- [Changelog](../CHANGELOG.md)
- [Project Context](../PROJECT_CONTEXT.md)
- [Documentation Report](../REPORT.md)

## Current Milestone

Private DEV/UAT preparation after Release Candidate 0.8.x. Current project work is focused on architecture governance and the Admissions subsystem foundation.

This document is a navigation and status snapshot. GitHub Issues, Pull Requests and CI remain the authoritative live workflow when GitHub write access is available.

## Active Branch

- Branch: `feature/adm-001-admissions-architecture`
- Base: `origin/develop`
- Scope: documentation only.

## Current Task

- `ADM-001`: спроектировать архитектурный foundation подсистемы «Приемная комиссия» без реализации backend/frontend/API/БД.
- Allowed changes: Markdown documentation only.
- Explicitly excluded: backend, frontend, database, migrations, API, infrastructure scripts and runtime configuration.

## Completed Tasks

Recent completed work reflected by current project notes and user-provided status:

- `DEMO-001`: completed. Login page no longer auto-populates credentials; dashboard/mobile/security checks were handled in the previous demo-readiness task.
- `DEMO-001.1`: completed. DEV-only login helper and temporary credential card workflow were completed before this documentation task.
- `DEMO-002`: code completed and pushed. GitHub Issue and Draft PR creation were blocked by missing GitHub write access in the Codex environment.
- `INFRA-PATHS`: completed. Canonical paths are `C:\!Projects\CollegePortal` on Windows and `/srv/college-dev` on Linux DEV.

## Roadmap Progress

The roadmap shows that the project has moved beyond the original MVP sequence into RC/UAT, installation lifecycle, access control, FIS/GIA integration, demo readiness and documentation governance.

Known roadmap maintenance need: older sections still name early tasks such as GUI-015, QR-002 and MOB-001 as near-term focus while later documentation records many downstream modules as completed or superseded.

## Next Planned Task

Recommended next planned task after `ADM-001`:

- create ADM-002 implementation task for admissions CRUD after review of the architecture PR;
- keep ADM-001 as documentation-only until the Draft PR is reviewed; do not merge automatically.

## Open Issues

Live GitHub Issues are the source of truth when accessible. From the local documentation perspective, the open process issues are:

- GitHub write access from the current Codex environment is unreliable and may block automatic Issue/PR creation.
- Documentation status is scattered across root documents, `docs/*`, release notes and task logs.
- Several project documents reference missing or renamed documentation files.

## Known Risks

- Documentation drift between `ROADMAP.md`, `TASKS.md`, `PROJECT_CONTEXT.md`, release notes and GitHub Project fields.
- Stale environment information for DEV/UAT/PROD paths, ports and release labels.
- Historical demo credentials or examples in documentation can be mistaken for active operational credentials.
- Multiple documentation indexes can diverge unless `docs/README.md` becomes the primary index.
- GitHub status cannot be considered confirmed from local files alone when `gh` or connector access is unavailable.

## Decisions (ADR Summary)

Key decision records and decision-oriented documents:

- [Architecture Decisions](ARCHITECTURE_DECISIONS.md)
- [ADR: FIS Gateway](adr/ADR-FIS-GATEWAY.md)
- [ADR: Integration Gateway](adr/ADR-INTEGRATION-GATEWAY.md)
- [Integration Gateway Protocol](INTEGRATION_GATEWAY_PROTOCOL.md)
- [Path Policy](PATH_POLICY.md)

Summary: CollegePortal uses evolutionary modularization, separate DEV/UAT/PROD safety boundaries, private storage for sensitive artifacts, explicit RBAC, audit logging, and controlled integration gateways for external systems.

## Background Agents

Background agents are documented in [Background Agents](AGENTS.md). Hubble, Mencius, Boole, Erdos, Bohr and Pasteur are names for Codex background-agent instances used by CollegePortal. Their roles are project conventions, not built-in Codex specializations, and the actual work of each run is defined by the prompt assigned to that agent.

## Backlog

ADM-001 backlog:

1. Этап 1 — CRUD абитуриентов.
2. Этап 2 — Документы.
3. Этап 3 — Конкурс.
4. Этап 4 — Приказы.
5. Этап 5 — Экспорт в ФИС.
6. Этап 6 — Личный кабинет абитуриента.


Documentation governance backlog:

- reconcile missing document references listed in [Documentation Report](../REPORT.md);
- decide whether older docs should be archived, renamed or replaced with redirects;
- add ownership and review cadence for key documents;
- align release/version status across README, CHANGELOG, VERSIONING and PROJECT_CONTEXT;
- keep docs-only work separated from implementation branches.

## Change History

| Date | Change |
| --- | --- |
| 2026-07-24 | Created the unified project status document for `DOCS-001`. |
| 2026-07-24 | Added ADM-001 Admissions architecture foundation. |
