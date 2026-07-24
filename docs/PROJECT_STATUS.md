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

Private DEV/UAT preparation after Release Candidate 0.8.x. Current project work is focused on demo readiness, access-control smoke hardening, FIS/GIA integration preparation, project documentation governance and UAT Center development.

This document is a navigation and status snapshot. GitHub Issues, Pull Requests and CI remain the authoritative live workflow when GitHub write access is available.

## Active Branch

- Branch: `feature/uat-001-center`
- Base: local `origin/develop`
- Scope: развитие существующего UAT Center без изменений Admissions.

## Current Task

- `UAT-001`: развитие центра обработки обращений.
- Allowed changes: UAT Center backend/frontend, миграции для UAT feedback, тесты и статусная документация.
- Explicitly excluded: Admissions, приемная комиссия, RBAC Admissions, UAT/PROD.

## Completed Tasks

Recent completed work reflected by current project notes and user-provided status:

- `DEMO-001`: completed. Login page no longer auto-populates credentials; dashboard/mobile/security checks were handled in the previous demo-readiness task.
- `DEMO-001.1`: completed. DEV-only login helper and temporary credential card workflow were completed before this documentation task.
- `DEMO-002`: code completed and pushed. GitHub Issue and Draft PR creation were blocked by missing GitHub write access in the Codex environment.
- `BACK-003`: foundation заявлений приемной комиссии реализован в отдельной feature-ветке.
- `DOCS-001`: единый центр документации создан и подготовлен к дальнейшему сопровождению.
- `INFRA-PATHS`: completed. Canonical paths are `C:\!Projects\CollegePortal` on Windows and `/srv/college-dev` on Linux DEV.

## Roadmap Progress

The roadmap shows that the project has moved beyond the original MVP sequence into RC/UAT, installation lifecycle, access control, FIS/GIA integration, demo readiness and documentation governance.

Known roadmap maintenance need: older sections still name early tasks such as GUI-015, QR-002 and MOB-001 as near-term focus while later documentation records many downstream modules as completed or superseded.

## Next Planned Task

Recommended next planned task after `UAT-001`:

- провести ручной UAT smoke карточки обращения на `/admin/uat`;
- выделить `UAT-002` для уведомлений, SLA и автоматической интеграции с GitHub при восстановлении устойчивого GitHub-доступа.

## Open Issues

Live GitHub Issues are the source of truth when accessible. From the local documentation perspective, the open process issues are:

- GitHub write access from the current Codex environment is unreliable and may block automatic Issue/PR creation.
- `git fetch origin` может блокироваться сетевой доступностью GitHub из текущей среды; если это повторится, нужно подтвердить CI/PR через GitHub UI.
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

Documentation governance backlog:

- reconcile missing document references listed in [Documentation Report](../REPORT.md);
- decide whether older docs should be archived, renamed or replaced with redirects;
- add ownership and review cadence for key documents;
- align release/version status across README, CHANGELOG, VERSIONING and PROJECT_CONTEXT;
- keep docs-only work separated from implementation branches.

## Change History

| Date | Change |
| --- | --- |
| 2026-07-25 | Started `UAT-001`: расширение UAT Center карточкой обращения, историей статусов, комментариями, фильтрами и GitHub Issue fields. |
| 2026-07-24 | Created the unified project status document for `DOCS-001`. |
