# CollegePortal Path Policy

INFRA-PATHS-001 fixes the canonical working paths for CollegePortal.

## Allowed Working Directories

Windows:

```text
C:\!Projects\CollegePortal
```

Linux DEV:

```text
/srv/college-dev
```

Windows Git worktrees are allowed only under:

```text
C:\!Projects\CollegePortal\.worktrees\<branch>
```

## Prohibited Paths

Do not use:

- legacy Windows project copies with lowercase project directory names;
- external worktree directories next to the project;
- temporary directories created under old project copies;
- new directories named after the old lowercase project folder.

The ViPNet workstation must not receive a full CollegePortal clone. It receives only the Gateway package in `C:\CollegePortalGateway`.

## Enforcement

- `scripts/repository/sync-collegeportal-windows.ps1` refuses prohibited Windows paths before clone, fetch or pull.
- `scripts/repository/assert-path-policy.ps1` checks a Windows checkout and repository text.
- `scripts/repository/assert-path-policy.sh` runs in CI and fails if forbidden legacy path markers are committed.
- `.github/workflows/ci.yml` runs the path policy check on pull requests and protected branch pushes.

If a prohibited path is detected, scripts fail with a Russian message instructing the operator to use `C:\!Projects\CollegePortal`.
