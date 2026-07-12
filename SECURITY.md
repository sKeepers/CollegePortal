# Security Policy

## Supported Version

Current private RC: `0.8.0-rc2`.

## Reporting Security Issues

Do not open public issues containing secrets, credentials, personal data, screenshots with private data or exploit details.

For the private project stage, report security issues to the repository maintainers through private GitHub channels or the agreed internal communication channel.

## Never Commit

- `.env`, passwords, tokens, SSH keys, TLS private keys or certificates;
- dumps, backups, release archives and runtime logs;
- real XLS/XLSX/CSV imports or exports;
- applicant documents, photos and private storage;
- screenshots containing passport data, SNILS, addresses or full identifiers.

## Required Checks

Before publishing or deploying:

- run tests and frontend build;
- review `git status --ignored` for runtime/private files;
- run secret scanning where available;
- verify `.gitignore` covers local artifacts;
- check that release artifacts contain only source and public assets.

## Production Readiness

See:

- `docs/PRODUCTION_SECURITY_CHECKLIST.md`
- `docs/PRODUCTION_DEPLOYMENT_READINESS.md`
- `docs/INSTALLATION_ACCEPTANCE_TEST.md`
