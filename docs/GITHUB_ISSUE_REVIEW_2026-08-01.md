# GitHub Issue Review: 2026-08-01

## Scope

Read-only review of `sKeepers/CollegePortal` Issues through authenticated GitHub CLI on DEV. No Issue, label, assignee, comment, Project field, pull request, or repository setting was changed.

## Snapshot

- Repository: `sKeepers/CollegePortal` (private), default branch `develop`.
- Open Issues: 16.
- Recently closed Issues returned by the review: `#27 DOCS-001`.
- Local active work: `UAT-002.2` on `feature/uat-002-2-mobile-access`; see [Active Work](ACTIVE_WORK.md).

## Active Priorities

### UAT And Portal Stabilization

| Issue | Status from review | Recommended action |
| --- | --- | --- |
| [#29 UAT-002.1](https://github.com/sKeepers/CollegePortal/issues/29) | Scope matches current portal stabilization, but its check result is stale (`333 passed`). | Keep open; add factual DEV checkpoint, tests, role-smoke results and current UAT-002.2 work after its completion. |
| [#4 UAT role-based acceptance](https://github.com/sKeepers/CollegePortal/issues/4) | Broad parent UAT issue. | Keep open; use as acceptance-cycle parent and link detailed UAT feedback/retest evidence. |
| [#24 ACCESS-002 hardware/mobile validation](https://github.com/sKeepers/CollegePortal/issues/24) | Still requires physical phone/HID validation. | Keep open; attach only sanitized evidence after QR, scanner and role smoke are complete. |
| [#22 ACCESS-001 dynamic QR foundation](https://github.com/sKeepers/CollegePortal/issues/22) | Describes an earlier architecture and legacy CP1 compatibility. | Update before closing: current implementation uses CP2, 30-second rotation and replay rejection; do not claim hash-only token storage unless verified against current schema. |

### Security And Operations

| Issue | Status from review | Recommended action |
| --- | --- | --- |
| [#1 SSH/TLS hardening](https://github.com/sKeepers/CollegePortal/issues/1) | Active and directly relevant. Browser still reports an untrusted HTTPS certificate. | Keep open; move SSH access to keys, rotate password outside Git, install trusted internal CA/certificate, then record verification. |
| [#5 private data security review](https://github.com/sKeepers/CollegePortal/issues/5) | Required before broad pilot access. | Keep open; perform after UAT stabilization and before pilot data import. |
| [#3 repeated installer message](https://github.com/sKeepers/CollegePortal/issues/3) | Small isolated infrastructure task. | Schedule independently; low coupling with current UAT work. |

### Data And Integrations

| Issue | Status from review | Recommended action |
| --- | --- | --- |
| [#2 pilot real-data import](https://github.com/sKeepers/CollegePortal/issues/2) | Depends on sanitized pilot files and responsible owners. | Keep blocked until files are received; never attach real import data to GitHub. |
| [#20 GIA-003](https://github.com/sKeepers/CollegePortal/issues/20) | Strict stop-gate: official SOAP binding/action/authentication contract is missing. | Keep blocked; do not guess SOAP envelope, actions or authentication. |
| [#17 GIA-002](https://github.com/sKeepers/CollegePortal/issues/17) | Gateway installation/contract verification parent. | Keep open, linked to #20. |
| [#15 GIA-001](https://github.com/sKeepers/CollegePortal/issues/15) | Earlier read-only exchange scope overlaps #17/#20. | Consolidate hierarchy or close as superseded only after preserving stop-gates. |
| [#9 INTEGRATION-HUB-001](https://github.com/sKeepers/CollegePortal/issues/9), [#7 FIS-GATEWAY-001](https://github.com/sKeepers/CollegePortal/issues/7), [#6 FIS-API-001](https://github.com/sKeepers/CollegePortal/issues/6) | Foundational integration issues overlap the GIA series. | Establish one parent/child hierarchy before starting new implementation. |

### Process And Demo

| Issue | Status from review | Recommended action |
| --- | --- | --- |
| [#25 DEMO-001.1](https://github.com/sKeepers/CollegePortal/issues/25) | Local project status records it as completed, but Issue remains open. | Verify deployment and tests, then close or update with actual evidence. |
| [#13 CODEX-WORKFLOW-001](https://github.com/sKeepers/CollegePortal/issues/13) | Broad workflow scope. | Update with completed `HANDOFF-001`; retain remaining Playwright/CI items as explicit sub-tasks. |

## Recommended Order

1. Complete and document `UAT-002.2`, then update `#29`, `#4`, and `#24` with sanitized results.
2. Complete `#1` trusted TLS and SSH-key hardening before any production-like use.
3. Perform `#5` security review before real pilot data.
4. Run `#2` only with sanitized pilot files and data-owner approval.
5. Resolve the FIS/Gateway Issue hierarchy and keep #20 as a hard official-contract stop-gate.
6. Reconcile or close stale completed issues `#25` and relevant portions of `#13`.

## Known Issue Metadata Gaps

- Most open Issues have no labels, assignees or recent updates.
- `#29` references an older test count and does not include later UAT-002.2 checkpoints.
- Current live code and older Issue descriptions can differ; verify claims against Git/DEV before closing any Issue.
