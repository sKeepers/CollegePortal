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

## GitHub Project Status

GitHub Projects v2 was not created because the current GitHub CLI token does not include `project` and `read:project` scopes. Classic repo project API also returned unavailable.

To complete this item, run outside the project directory if needed:

```bash
gh auth refresh -s project,read:project
```

Then create the project:

```bash
gh project create --owner sKeepers --title "CollegePortal Roadmap"
```
