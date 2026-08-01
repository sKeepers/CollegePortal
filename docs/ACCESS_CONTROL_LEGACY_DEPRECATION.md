# ACCESS-002: Legacy CP1 / Plain Token Deprecation Plan

## Current Status

ACCESS-001 keeps legacy DigitalIdentity tokens compatible with the new checkpoint flow:

- plain token;
- `CP1:<token>` prefix;
- duplicate suppression window from `identity.duplicate_scan_window_seconds`.

This compatibility exists only to avoid breaking already issued QR passes while CP2 dynamic QR is rolled out.

## Security Boundary

Legacy mode must not gain CP2 privileges:

- no CP2 signature semantics;
- no CP2 TTL semantics;
- no CP2 replay model;
- no access to raw CP2 token storage;
- no bypass of RBAC or audit.

All new personal pass UI should prefer CP2. Legacy QR is fallback only.

## Required Controls

- [ ] Legacy scans are marked as `token_type=legacy` in access audit metadata.
- [ ] Legacy duplicate scans are suppressed only inside configured duplicate window.
- [ ] Legacy mode remains covered by tests.
- [ ] Operator guide explains legacy behavior as compatibility mode.
- [ ] Dashboard/reporting can distinguish legacy and CP2 paths if required by operations.

## Proposed Timeline

| Phase | Target | Action |
| --- | --- | --- |
| Compatibility | Release 0.10 | CP2 is primary, CP1/plain remains enabled. |
| Warning | Release 0.11 | Show admin warning for legacy scans and report count. |
| Disable by default | Release 0.12 | Add feature flag defaulting legacy to disabled for new installs. |
| Removal | Release 1.0 candidate | Remove CP1/plain acceptance after migration confirmation. |

## Migration Path

1. Issue CP2 dynamic QR to test student/operator cohorts.
2. Confirm mobile and HID scanner smoke.
3. Count legacy scan usage for at least one pilot period.
4. Reissue active passes through CP2-capable mobile/printed process.
5. Disable legacy mode in DEV.
6. Repeat smoke tests.
7. Disable legacy mode in UAT after explicit approval.
8. Remove legacy code only after documented production migration.

## ACCESS-002 Decision

ACCESS-002 does not remove legacy compatibility. It documents it as technical debt and requires a follow-up ACCESS-003/ACCESS-LEGACY task before disabling it.
