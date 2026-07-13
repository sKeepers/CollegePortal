# GitHub Repository Setup

Task: GITHUB-001.

## Target Repository

- Account: `sKeepers`
- Preferred repository: `CollegePortal`
- Visibility: private
- Primary branches: `main`, `develop`
- Development branch: `develop`

## Safety Rules

Do not push or attach:

- `.env` files, passwords, tokens, SSH keys, TLS private keys or certificates;
- DEV/UAT/PROD database dumps, backups, runtime storage or private documents;
- real XLS/XLSX/CSV imports or exports;
- screenshots containing passport data, SNILS, addresses or unnecessary personal data;
- release archives unless they are explicitly reviewed source-only artifacts.

## Pre-Push Audit

Performed before first GitHub push:

- `git status` and branch check;
- tracked forbidden path scan with `git ls-files`;
- ignored runtime/private file review with `git status --ignored`;
- tracked snapshot pattern scan with `git grep`;
- history path scan with `git rev-list --objects --all`;
- gitleaks Docker scan over full Git history.

Result: no leaks found by gitleaks. The only tracked XLSX files are official external service reference templates under `docs/external-services/ФИС ФРДО/`; new real import/export files are ignored.

## CI

`.github/workflows/ci.yml` runs:

- backend Laravel tests on PHP 8.4;
- frontend Vite build on Node 22;
- gitleaks secret scan over repository history.

## Release

GitHub Release `v0.8.0-rc2` should be private-repository release notes for the validated RC. The source release archive remains generated from `/srv/college-dev` and must be reviewed before attaching as a release asset.


## Publication Result

- Repository: `https://github.com/sKeepers/CollegePortal`
- Visibility: private
- Remote: `https://github.com/sKeepers/CollegePortal.git`
- Branches pushed: `develop`, `main`
- Default branch: `develop`
- Latest pushed development commit: `c71b92b`
- Release tag: `v0.8.0-rc2`
- Release URL: `https://github.com/sKeepers/CollegePortal/releases/tag/v0.8.0-rc2`
- Initial issues: #1 through #5
- CI status after workflow fix: success on `develop` and `main`

## GitHub Project Roadmap

- Project: `CollegePortal Roadmap`
- Owner: `sKeepers`
- Project number: `2`
- URL: `https://github.com/users/sKeepers/projects/2`
- Type: GitHub Projects v2

Configured board statuses:

- `Backlog`
- `Ready`
- `In Progress`
- `Review`
- `Testing`
- `Done`

Configured fields:

- `Status`: single select, used as the board column field;
- `Priority`: `P0 Critical`, `P1 High`, `P2 Medium`, `P3 Low`;
- `Type`: `Bug`, `Task`, `Security`, `UAT`, `Infra`, `Release`, `Data Import`;
- `Module`: `Infrastructure`, `Security`, `Data Import`, `UAT`, `Release`, `Repository`;
- `Target release`: text field for release labels such as `0.8.0-rc2`, `0.9`, `1.0`.

Issues added to the roadmap:

| Issue | Status | Priority | Type | Module | Target release |
| --- | --- | --- | --- | --- | --- |
| `#1` INFRA: harden SSH and trusted TLS before PROD | Ready | P1 High | Security | Infrastructure | 0.9 |
| `#2` DATA: pilot real data import checklist | Ready | P1 High | Data Import | Data Import | 0.8.0-rc2 |
| `#3` INFRA: improve repeated install protection message | Backlog | P2 Medium | Infra | Infrastructure | 0.8.x |
| `#4` UAT: run first role-based acceptance cycle | Ready | P1 High | UAT | UAT | 0.8.0-rc2 |
| `#5` SECURITY: review private data handling before wider pilot | Ready | P1 High | Security | Security | 0.9 |

Maintenance rules:

- Keep all roadmap items linked to GitHub Issues, not draft-only cards, whenever a task requires traceability.
- Use `Status` for board movement and keep `Priority`, `Type`, `Module`, and `Target release` filled before moving an item to `Ready`.
- Do not put real personal data, import files, passwords, private keys, dumps, backups, or production certificates into issues or project notes.

## CI-001 Journal Engine CI Fix

GitHub Actions failure observed on `901a22d`:

- Workflow: `CI`
- Job: `Backend tests`
- Failed test: `Tests\Feature\JournalEngineApiTest::test_teacher_scope_and_control_mode_for_study_office`
- Assertion: expected `data` count `1`, actual count `0` for `GET /api/journal/lessons?mode=week` as teacher.
- CI environment: GitHub-hosted `ubuntu-24.04`, PHP `8.4`, Laravel test runner, SQLite from `backend/.env.example`.

Root cause:

- The test fixture created schedule and journal lessons on fixed date `2026-07-12`.
- The API mode `week` correctly uses `today()->startOfWeek()` / `today()->endOfWeek()`.
- Once CI ran on `2026-07-13`, `2026-07-12` was outside the current week and the teacher-scoped query returned zero records.
- Local DEV did not expose this earlier because the full test check had not been rerun after the calendar crossed the week boundary.

Fix:

- `JournalEngineApiTest` now freezes Laravel's test clock with `Carbon::setTestNow('2026-07-12 09:00:00')` and clears it in `tearDown()`.
- The RBAC scenario was strengthened to assert exact lesson IDs: teacher sees only own lessons, teacher does not get full `control` visibility, a user with teacher role but no linked `Teacher` profile sees no lessons, and study office with `journal.view_all` sees all lessons.

Local verification before push:

- Targeted journal scope test: passed 10 consecutive runs.
- Full backend suite: `248 passed (1407 assertions)`.
- Frontend build: `npm run build` succeeded.

## Branch Protection

Branch protection was checked for `develop` and `main` with GitHub REST API after GITHUB-001.1.

Current result for both branches:

```text
HTTP 403: Upgrade to GitHub Pro or make this repository public to enable this feature.
```

Required status checks and direct-push restrictions cannot be enabled for this private repository on the current GitHub plan. Until the plan supports branch protection, keep the manual rule: push fixes to `develop`, wait for green CI, then fast-forward `main` from `develop`.
