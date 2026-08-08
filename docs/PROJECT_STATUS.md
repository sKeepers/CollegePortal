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

Private DEV/UAT preparation after Release Candidate 0.8.x. Current project work is focused on final portal stabilization before a broader manual UAT pass.

Текущий релиз — `0.8.0-rc6`, тег `v0.8.0-rc6`, установлен на PROD `https://portal.skki.ru`. Единственный источник истины о номере версии — `installer/VERSION`; в остальных документах номер допустим только в примерах команд и в исторических записях.

This document is a navigation and status snapshot. GitHub Issues, Pull Requests and CI remain the authoritative live workflow when GitHub write access is available.

## Active Branch

- Branch: `develop` — ствол проекта, CI запускается на нём и на ветках задач.
- Ветка текущей задачи, локальный и серверный HEAD, выполненные проверки и блокеры — в [ACTIVE_WORK.md](ACTIVE_WORK.md).

## Current Task

Раздел ниже описывает `UAT-002.1` и оставлен как история: задача завершена. Текущая работа ведётся в [ACTIVE_WORK.md](ACTIVE_WORK.md).

- `UAT-002.1`: Final portal stabilization.
- Разрешенные изменения: user-facing validation/localization fixes, Admissions Foundation wizard stabilization, role dashboard cleanup, reusable splitter hardening, existing QR pass readiness on DEV, DEV data cleanup, HTTPS-only DEV entrypoint, dynamic QR TTL and version/build metadata.
- Дополнительная стабилизация по ручному smoke: демо-студенты и преподаватели получают разные синтетические ФИО, все demo Student/Teacher связаны с `people`, преподаватели создаются как `employees`, посещаемость отдает локальное время без UTC-сдвига, мастер заявления не пропускает очевидно некорректные шаги до финальной кнопки, master-detail workspace не должен уводить правую карточку за край экрана.
- Дополнительный security hardening по `PROJECT_ANALYSIS.md`: API-токены переводятся на индексированный SHA-256 lookup hash, получают TTL, login и authenticated API получают rate limiting. Frontend-auth, шифрование ПДн/backups и TLS/security headers остаются отдельными задачами.
- Явно исключено: новая независимая QR-реализация, новый ФИС/SOAP flow, PROD/UAT changes and merge to `develop`.

## Completed Tasks

Recent completed work reflected by current project notes and user-provided status:

- `DEMO-001`: completed. Login page no longer auto-populates credentials; dashboard/mobile/security checks were handled in the previous demo-readiness task.
- `DEMO-001.1`: completed. DEV-only login helper and temporary credential card workflow were completed before this documentation task.
- `DEMO-002`: code completed and pushed. GitHub Issue and Draft PR creation were blocked by missing GitHub write access in the Codex environment.
- `INFRA-PATHS`: completed. Canonical paths are `C:\!Projects\CollegePortal` on Windows and `/srv/college-dev` on Linux DEV.
- `ADM-001`: completed as architecture documentation for the Admissions subsystem.
- `ADM-002`: completed as data model documentation for the Admissions subsystem.
- `ADM-003`: completed as API, RBAC and workflow documentation for the Admissions subsystem.
- `ADM-004`: completed as implementation strategy documentation for the Admissions subsystem.
- `BACK-001`: completed as read-only Reference Data foundation for Admissions.
- `BACK-002`: completed as Person/Applicant foundation with safe Person linking and read-only applicants API.
- `BACK-003`: completed as foundation `AdmissionApplication` with draft/update/register API, pending merge to `develop`.
- `BACK-003.1`: completed as technical isolation between legacy `/admissions` and new Admissions Foundation through explicit `record_type`.
- `BACK-004`: completed as Program Choices foundation for multiple education programs per application.
- `FRONT-001`: completed as read-only Admissions Foundation workspace on `/admissions/foundation`.
- `BACK-005`: completed as Admissions Foundation documents, private files, SNILS hashing and document readiness API.
- `BACK-005.1`: completed as Documents Foundation hardening: application-document link, document version chain, XSD education fields and FIS dictionary mapping.
- `FRONT-002`: completed as Admissions Foundation editor workspace using existing backend endpoints.
- `BACK-006`: completed as Person & Applicant Management API for FRONT-003.
- `FRONT-003`: completed as Person & Applicant Management UI on `/admissions/foundation`.
- `RC1-DEV`: completed as DEV release fixes for Admissions Foundation audit data redaction.
- `GUI-009`: completed as Dashboard and navigation layout hardening: collapsible sidebar groups, visible `/schedule`, People splitter and compact Dashboard widgets.
- `UAT-002`: completed as Portal UX, RBAC and Admissions stabilization baseline deployed to DEV branch.

## Roadmap Progress

The roadmap shows that the project has moved beyond the original MVP sequence into RC/UAT, installation lifecycle, access control, FIS/GIA integration, demo readiness and documentation governance.

Known roadmap maintenance need: older sections still name early tasks such as GUI-015, QR-002 and MOB-001 as near-term focus while later documentation records many downstream modules as completed or superseded.

## Next Planned Task

Recommended next planned task after `UAT-002.1`:

- `TEST-001`: regression coverage for Admissions Foundation full operator workflow and registered read-only behavior;
- `UAT-002.1-DEV`: deploy current branch to DEV and perform role-based smoke on `http://192.168.34.114:5174`;
- keep FIS XML/package generation as a separate task after mapping review.

## Open Issues

Live GitHub Issues are the source of truth when accessible. From the local documentation perspective, the open process issues are:

- GitHub write access from the current Codex environment is unreliable and may block automatic Issue/PR creation.
- Documentation status is scattered across root documents, `docs/*`, release notes and task logs.
- Several project documents reference missing or renamed documentation files.
- Security review 30.07.2026 выявил production blockers: bearer token в `localStorage`, отсутствие encryption-at-rest для ПДн/backups, необходимость TLS/security headers и PostgreSQL-like CI checks.
- DEV test users are documented in [Test Users](TEST_USERS.md). They are DEV-only and must not be used in PROD.
- UAT-002 QR integration reuses the existing Digital Identity and Access Gate implementation; no BACK-007/new QR API is planned for this correction.
- DEV HTTPS endpoint for browser/mobile UAT is `https://192.168.34.114:5443`; port `5174` remains HTTP-only and must not be opened as HTTPS.
- Demo data is documented in [Demo Data](DEMO_DATA.md) and can be recreated without real personal data.
- После reseed demo data разделы `Люди`, `Студенты`, `Преподаватели` и `Сотрудники` должны показывать согласованный контингент: students/teachers не должны существовать как изолированные записи без Person/Employee-связей.

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
- [ADR-002: Приемная комиссия](adr/ADR-002_ПРИЕМНАЯ_КОМИССИЯ.md)
- [ADR-003: Модель данных приемной комиссии](adr/ADR-003_МОДЕЛЬ_ДАННЫХ_ПРИЕМНОЙ_КОМИССИИ.md)
- [ADR-004: API приемной комиссии](adr/ADR-004_API_ПРИЕМНОЙ_КОМИССИИ.md)
- [ADR-005: Стратегия реализации приемной комиссии](adr/ADR-005_СТРАТЕГИЯ_РЕАЛИЗАЦИИ.md)
- [ADR-006: Жизненный цикл документов Admissions](adr/ADR-006_ЖИЗНЕННЫЙ_ЦИКЛ_ДОКУМЕНТОВ_ADMISSIONS.md)
- [Integration Gateway Protocol](INTEGRATION_GATEWAY_PROTOCOL.md)
- [Path Policy](PATH_POLICY.md)

Summary: CollegePortal uses evolutionary modularization, separate DEV/UAT/PROD safety boundaries, private storage for sensitive artifacts, explicit RBAC, audit logging, and controlled integration gateways for external systems.

## Background Agents

Background agents are documented in [Background Agents](AGENTS.md). Hubble, Mencius, Boole, Erdos, Bohr and Pasteur are names for Codex background-agent instances used by CollegePortal. Their roles are project conventions, not built-in Codex specializations, and the actual work of each run is defined by the prompt assigned to that agent.

## Backlog

ADM-001/ADM-002/ADM-003/ADM-004 admissions backlog:

1. BACK-001 — Reference Data, статусы и permissions приемной комиссии.
2. BACK-002 — Applicant foundation и безопасная связь с Person.
3. BACK-003 — Application foundation.
4. BACK-003.1 — Isolation между legacy `/admissions` и новым `AdmissionApplication` foundation.
5. BACK-004 — Program Choices foundation: несколько выбранных образовательных программ заявления с приоритетами.
6. FRONT-001 — Read-only workspace Admissions Foundation: `/admissions/foundation`.
7. BACK-005 — foundation документов заявления, private files, СНИЛС и структурированная комплектность.
8. BACK-005.1 — hardening Documents Foundation: версии и связь с заявлением.
9. FRONT-002 — editor workspace Admissions Foundation: мастер заявления, документы, файлы, readiness, FIS, история.
10. BACK-006 — Person & Applicant Management API: create/update Person, create/update/archive Applicant, duplicate check, merge stop-gate.
11. FRONT-003 — Person & Applicant Management UI.
12. UAT-002 — role-based stabilization of Admissions Foundation, Dashboard, QR menu integration and smoke documentation.
13. UAT-002.1 — final portal stabilization before broader manual UAT.
14. SEC-001 — API token hardening: indexed lookup hash, token TTL and rate limiting.
15. SEC-002 — frontend-auth hardening: HttpOnly Secure cookie/Sanctum вместо bearer token в `localStorage`.
16. SEC-003 — encryption-at-rest, private storage, backup encryption and production TLS/security headers.
17. Этап 1 — CRUD абитуриентов.
18. Этап 2 — Документы.
19. Этап 3 — Конкурс.
20. Этап 4 — Приказы.
21. Этап 5 — Экспорт в ФИС.
22. Этап 6 — Личный кабинет абитуриента.

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
| 2026-07-24 | Added ADM-002 Admissions data model foundation. |
| 2026-07-24 | Added ADM-003 Admissions API and process foundation. |
| 2026-07-24 | Added ADM-004 Admissions implementation strategy. |
| 2026-07-24 | Added BACK-001 Admissions read-only reference foundation. |
| 2026-07-24 | Added BACK-002 Admissions Person/Applicant foundation. |
| 2026-07-24 | Added BACK-003 Admissions Application foundation. |
| 2026-07-24 | Added BACK-003.1 Admissions legacy/foundation isolation through explicit `record_type`. |
| 2026-07-24 | Added BACK-004 Admissions Program Choices foundation. |
| 2026-07-26 | Added FRONT-001 read-only Admissions Foundation workspace. |
| 2026-07-26 | Added BACK-005 Admissions document foundation, private files, SNILS hashing and readiness API. |
| 2026-07-26 | Added BACK-005.1 Documents Foundation hardening and ADR-006. |
| 2026-07-26 | Added FRONT-002 Admissions Foundation editor workspace. |
| 2026-07-28 | Added BACK-006 Person & Applicant Management API for FRONT-003. |
| 2026-07-28 | Added FRONT-003 Person & Applicant Management UI for Admissions Foundation. |
| 2026-07-28 | Prepared Admissions Foundation RC1 readiness report and release audit. |
| 2026-07-28 | Started GUI-009 layout hardening: sidebar sections, `/schedule`, People splitter and Dashboard layout persistence. |
| 2026-07-28 | Started UAT-002 stabilization: Admissions menu cleanup, user-facing localization, Dashboard RBAC behavior and existing QR pass integration. |
