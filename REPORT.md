# Documentation Report

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)

## Scope

This report was prepared for `DOCS-001`. It records documentation duplication, outdated sections and contradictions. It does not automatically correct product facts, business logic, code, infrastructure, database state or API behavior.

## Method

Reviewed the root project documents and representative files under `docs/`, then searched for stale task labels, missing document references, release/version markers, environment paths and credential-like examples.

## Summary

The documentation set is broad and valuable, but status information is currently spread across several root files, release notes, environment documents and GitHub workflow notes. A single project status document and a single documentation index should become the normal entrypoint for project management.

## Duplications

- Documentation indexes overlap between `PROJECT_CONTEXT.md`, `docs/ARCHITECTURE_DOCUMENTATION.md` and the new `docs/README.md`.
- Environment descriptions appear in `PROJECT_CONTEXT.md`, `docs/DEV_ENVIRONMENT.md`, `docs/ENVIRONMENTS.md`, `docs/DEV_HTTPS.md`, `docs/UAT_SERVER.md`, `docs/DEPLOYMENT.md` and installer docs.
- GitHub workflow information appears in `docs/GITHUB_REPOSITORY.md`, `docs/GIT_WORKFLOW.md`, `docs/CODEX_WORKFLOW.md`, `PROJECT_CONTEXT.md` and task logs.
- Release readiness and UAT status are described in several places: `CHANGELOG.md`, `docs/INSTALLATION_ACCEPTANCE_TEST.md`, `docs/UAT_*`, `docs/PRODUCTION_DEPLOYMENT_READINESS.md` and `PROJECT_CONTEXT.md`.

## Outdated Sections

- `ROADMAP.md` still contains an early “Ближайший порядок развития” section with GUI-015, QR-002 and MOB-001, while later task history records many related modules as completed, superseded or expanded.
- `PROJECT_CONTEXT.md` includes historical Release 0.7 notes and older import limitations that may no longer describe the current feature set.
- `PROJECT_CONTEXT.md` and several deployment docs still mention `/home/andale/college_portal` as PROD context; this may be intentionally historical, but it conflicts with the newer canonical-path policy and should be clearly labeled.
- `docs/GITHUB_REPOSITORY.md` includes GitHub Project and branch-protection status that can drift quickly and should be periodically refreshed against GitHub.
- Version references span Release 0.7, `0.8.0-rc2` and later feature work; the current release status is not expressed in one authoritative place before `docs/PROJECT_STATUS.md`.

## Contradictions

- `PROJECT_CONTEXT.md` references documents that are not present in the repository: `docs/PRODUCT_VISION.md`, `docs/PHILOSOPHY.md` and `docs/ACCESS_CONTROL_CONCEPT.md`.
- Some docs treat QR/pass/access work as future scope, while later documents describe access-control and mobile-scanner implementation work.
- The old environment examples with demo credentials conflict with the newer demo/security expectation that credentials are not auto-filled or exposed in UI bundles.
- Root `AGENTS.md` is an operational instruction file, while the new `docs/AGENTS.md` is a role catalog for background-agent conventions. Without an explicit distinction these can be confused.

## Missing Or Weak Links

- No previous `docs/README.md` existed as a complete documentation index.
- Root documents did not consistently link to project status, agent roles and documentation report.
- ADR documents existed, but there was no short status-level ADR summary for day-to-day project management.

## Recommendations

- Keep `docs/PROJECT_STATUS.md` as the current management snapshot and update it at the end of major tasks.
- Treat `docs/README.md` as the canonical documentation index; reduce index-like sections elsewhere over time.
- Decide whether missing documents should be created, renamed or removed from references.
- Split historical notes from current operational facts in `PROJECT_CONTEXT.md`.
- Add a lightweight documentation review step to release tasks: validate links, release version, paths, GitHub status and credential examples.
- Avoid publishing real credentials, personal data, private endpoints or runtime secrets in examples.

## Non-Actions

The report intentionally does not rewrite roadmap priorities, deployment facts, release status or credential examples. Those changes need separate owner approval because they can alter project interpretation and operational instructions.
