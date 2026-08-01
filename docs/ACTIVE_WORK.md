# Active Work

## Purpose

This file is the operational handoff record for the current CollegePortal session. It is the entrypoint for a new chat, a new agent, or a different engineer after reading `AGENTS.md`.

Update it before ending a session, before changing to a substantially different task, and after each DEV deployment.

## Updated

- Date: 2026-08-01
- Local worktree: `C:\!Projects\CollegePortal\.worktrees\uat-002-2-mobile-access`
- DEV checkout: `/home/andale/CollegePortal`

## Git State

- Active worktree branch: `feature/uat-002-2-mobile-access`
- Last deployed DEV checkpoint: `c3c2a6def45ee9adf82d0d459355e77826d5659c`
- DEV branch: `feature/uat-002-1-final-stabilization`
- Local application changes not committed or deployed:
  - password visibility and session persistence controls on login;
  - scope-safe teacher journal reference options;
  - ownership scope and role-specific views for schedule.

Do not discard, overwrite, or deploy the uncommitted changes without reviewing `git diff` and completing their tests.

## DEV Access

- Browser portal: `https://84.54.208.134:5443`
- Internal DEV portal: `https://192.168.34.114:5443`
- Health check: `http://127.0.0.1:8001/health/live`
- Containers: `docker compose -f /home/andale/CollegePortal/docker-compose.yml`
- Never put passwords, tokens, private keys, or personal data in this file.

## Current Task

`UAT-002.2`: role-based portal stabilization after mobile UAT.

GitHub Issues are accessible read-only through `gh` on DEV. The current review is [GitHub Issue Review 2026-08-01](GITHUB_ISSUE_REVIEW_2026-08-01.md); do not edit Issues without an explicit task.

Current accepted requirements:

1. Login: password visibility, browser-managed password saving, and persistent/session login choice.
2. Teacher: scoped journal with attendance and grading, without schedule editor.
3. Student: personal schedule only, week/month views, personal grade details, and no teacher-only journal workspace.
4. Access gate: compact, low-load phone scanner.
5. Responsive role routing for phone, tablet, HD, FullHD, and wider desktop viewports.

## Verified Checkpoints

- `328572e`: dynamic QR, replay protection, student journal restriction.
- `c3c2a6d`: student schedule access and mobile date navigation.
- `88b1f83`: compact mobile scanner workspace.
- `86108ff`: QR-first personal pass with countdown.

## Next Actions

1. Review and complete the current local diff.
2. Add backend tests for teacher/student schedule ownership scope.
3. Add student grade-detail UI and tests.
4. Run `php artisan test` and `npm run build` in DEV containers.
5. Create a task checkpoint, apply it to DEV only after approval, and update this file with the new DEV HEAD.
6. Run manual UAT with explicit URL, role, account, and expected result.

## Handoff Checklist

Before ending a session or opening a new chat:

1. Run `git status --short`, `git diff --check`, and `git log --oneline -10`.
2. Update this file with branch, local/DEV HEAD, uncommitted work, verified checks, blockers, and exact next actions.
3. Commit completed logical work; do not commit incomplete or unreviewed changes unless explicitly requested.
4. State whether DEV and PROD were changed.
5. Offer a new chat when the task is complete, when the context is near its practical limit, or when the next task is independent.

## New Chat Prompt

```text
Read AGENTS.md, docs/ACTIVE_WORK.md, TASKS.md, and docs/UAT_002_REPORT.md.
Run git status --short, git diff --check, and git log --oneline -10.
Compare the local branch and DEV HEAD stated in ACTIVE_WORK.md.
Continue only the listed Next Actions. Do not discard uncommitted work.
```
