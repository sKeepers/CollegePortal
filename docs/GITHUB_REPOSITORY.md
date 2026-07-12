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
