# Contributing

CollegePortal is currently a private RC/UAT project. Contributions are accepted through controlled GitHub workflow only.

## Branches

- `main`: stable release-ready history.
- `develop`: active integration branch.
- `feature/<task-id>`: optional task branches for larger changes.

## Commit Format

Use the task id first:

```text
GUI-012: add teachers page
INFRA-008: validate installer on clean UAT server
GITHUB-001: prepare GitHub repository
```

## Before Commit

Run the relevant checks:

```bash
docker compose exec -T backend php artisan test
docker compose exec -T frontend npm run build
git status
git diff --check
```

Never commit secrets, real personal data, dumps, backups, private documents, runtime storage, certificates or release archives.

## Pull Requests

Each PR should include:

- task id and summary;
- changed modules;
- test/build result;
- migration notes if any;
- screenshots for UI changes;
- security/data handling notes when applicable.

## Production Safety

Development work is done in `/srv/college-dev`. PROD and UAT must not be changed unless the task explicitly says so.
