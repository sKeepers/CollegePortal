# Repository Synchronization

## Rules

- GitHub is canonical.
- Sync `develop` with fast-forward only.
- Never use `git reset --hard` in sync helpers.
- Never delete untracked files during sync.
- Refuse to pull if the working tree is dirty.
- Do not update `main`, UAT or PROD from this workflow.
- Do not clone CollegePortal to the ViPNet workstation; deploy only the Gateway Agent package.

## Linux DEV Sync

```bash
scripts/repository/sync-collegeportal-linux.sh /srv/college-dev
```

The script checks origin, dirty state, runs `git fetch --all --prune`, checks out `develop`, pulls with `--ff-only`, then prints branch, HEAD and ahead/behind.

## Windows Local Copy Sync

```powershell
powershell -ExecutionPolicy Bypass -File scripts\repository\sync-collegeportal-windows.ps1 -RepoPath C:\!Projects\college_portal
```

The script checks Git availability, verifies remote, prints status, refuses dirty working trees, fetches/prunes, checks out `develop`, pulls with `--ff-only`, and prints the new HEAD.

## Inventory: REPO-SYNC-001

| Hostname | OS | Path | Branch | HEAD | Remote | State | Ahead/Behind |
|---|---|---|---|---|---|---|---|
| moodle | Ubuntu 24.04.3 LTS | `/srv/college-dev` | `develop` | `a64b341` | `https://github.com/sKeepers/CollegePortal.git` | clean | ahead 0 / behind 0 |

No other Linux copy with origin `github.com/sKeepers/CollegePortal` was found in the safe scan scope (`/srv`, `/home/andale`, excluding private storage and backups).

## INFRA-ACCESS-001.1 Clarification

The factual CollegePortal DEV repository is currently on `192.168.34.104` / hostname `moodle` at `/srv/college-dev`.

`192.168.34.114` is SSH-reachable but key login for `andale` is not configured yet. Do not treat it as the primary DEV and do not move `/srv/college-dev` there without a separate infrastructure decision.

## UAT and PROD

UAT (`192.168.34.17`, `/opt/college-portal`) must be updated only by installer/update release flow, not by Git pull. PROD was not contacted or inventoried.

## Windows Development Copy

The primary Windows development copy is now `C:\!Projects\CollegePortal`. The old `C:\!Projects\college_portal` copy is considered stale/dirty and must not be used for Gateway builds. Use `scripts/repository/sync-collegeportal-windows.ps1`; it clones `develop` if missing, refuses dirty trees and uses `pull --ff-only`.
