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

This report was prepared for `DOCS-001` and extended by `DOCS-002`. The original findings are preserved as audit history; current resolution status is tracked in the `DOCS-002 Resolution Status` section. The report does not automatically correct product facts, business logic, code, infrastructure, database state or API behavior.

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

- At DOCS-001 time, `ROADMAP.md` contained an early “Ближайший порядок развития” section with GUI-015, QR-002 and MOB-001, while later task history recorded many related modules as completed, superseded or expanded.
- `PROJECT_CONTEXT.md` includes historical Release 0.7 notes and older import limitations that may no longer describe the current feature set.
- At DOCS-001 time, `PROJECT_CONTEXT.md` and several deployment docs repeated `/home/andale/college_portal` as PROD context. The value may be intentionally historical or canonical in `docs/ENVIRONMENTS.md`, but duplicates outside the environment inventory need labels or links.
- `docs/GITHUB_REPOSITORY.md` includes GitHub Project and branch-protection status that can drift quickly and should be periodically refreshed against GitHub.
- Version references span Release 0.7, `0.8.0-rc2` and later feature work; the current release status is not expressed in one authoritative place before `docs/PROJECT_STATUS.md`.

## Contradictions

- At DOCS-001 time, `PROJECT_CONTEXT.md` referenced documents that were not present in the repository: `docs/PRODUCT_VISION.md`, `docs/PHILOSOPHY.md` and `docs/ACCESS_CONTROL_CONCEPT.md`.
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

## DOCS-002 Resolution Status

| Issue from DOCS-001 | Status | Decision |
| --- | --- | --- |
| Missing `docs/PRODUCT_VISION.md` reference | Resolved | Replaced with existing `VISION.md`; no empty placeholder created. |
| Missing `docs/PHILOSOPHY.md` reference | Resolved | Replaced with existing `GOVERNANCE.md`, `docs/ARCHITECTURE_DECISIONS.md` and `docs/PATH_POLICY.md`. |
| Missing `docs/ACCESS_CONTROL_CONCEPT.md` reference | Resolved | Replaced with existing `docs/IDENTITY_DOMAIN.md`, `docs/DIGITAL_PASSES.md` and `docs/ACCESS_GATE.md`. |
| Stale ROADMAP near-term focus GUI-015 / QR-002 / MOB-001 | Resolved | Replaced with explicit status table and note that the next large functional stage requires approval. |
| QR/access described as both future and implemented | Partially resolved | Core docs now use implemented / partially implemented / planned / smoke-test / production-deployment statuses. Some deep historical release notes remain intentionally historical. |
| Credential examples in Markdown | Partially resolved | Obvious active-looking examples were replaced with placeholders. Historical notes that describe storage policy remain, but should be reviewed again before public publication. |
| Environment duplication | Partially resolved | `docs/ENVIRONMENTS.md` is marked as canonical and key duplicates now point to it. Full deduplication of all deployment-era docs is deferred to a dedicated documentation cleanup. |
| GitHub workflow duplication | Partially resolved | `docs/GITHUB_REPOSITORY.md` is marked as canonical for GitHub workflow and `docs/RELEASE_PROCESS.md` for release artifacts. Older task history remains unchanged. |
| Multiple documentation indexes | Deferred | `docs/README.md` is canonical, but older index-like sections are retained to avoid a broad rewrite. Future cleanup can compress them into links. |

## DOCS-002 Decisions

- Empty documents were not created for missing references.
- Product vision uses existing `VISION.md`.
- Project principles use existing governance, ADR and path-policy documents.
- Access Control concept uses existing Identity, Digital Passes and Access Gate documents.
- `docs/ENVIRONMENTS.md` is the canonical environment inventory.
- `docs/GITHUB_REPOSITORY.md` is the canonical GitHub workflow document.
- `docs/RELEASE_PROCESS.md` is the canonical release artifact process document.

## Deferred Items

- Deep archival cleanup of historical Release 0.7 / 0.8 notes.
- Full consolidation of every environment paragraph across older deployment and UAT documents.
- Public-publication review of every historical credential-like placeholder.
- Review of remaining feature-specific docs after their PRs are merged or closed.
