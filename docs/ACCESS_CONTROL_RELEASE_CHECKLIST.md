# ACCESS-002: Access Control Release Checklist

## Purpose

This checklist defines merge readiness for the Access Control foundation in PR #23.

## Automated Checks

- [x] GitHub Actions backend tests pass on PR #23.
- [x] GitHub Actions frontend build passes on PR #23.
- [x] GitHub Actions Path policy passes on PR #23.
- [x] GitHub Actions Secret scan passes on PR #23.
- [ ] Migration smoke has been run on DEV.
- [ ] CP2 QR decode regression has been run with a synthetic token and independent decoder.
- [ ] Playwright desktop smoke has been run for `/access/pass` and `/access/checkpoint`.
- [ ] Playwright mobile viewport smoke has been run for `/access/pass`, `/m/student/pass`, `/access/mobile-scanner`.

## Manual Checks

- [ ] `docs/ACCESS_CONTROL_MOBILE_TEST.md` completed on Android Chrome.
- [ ] `docs/ACCESS_CONTROL_HARDWARE_TEST.md` completed with real HID 2D scanner.
- [ ] Allowed/denied matrix completed.
- [ ] Entry/exit sequence completed.
- [ ] Legacy CP1/plain compatibility completed.
- [ ] Operator override completed.
- [ ] Audit and no-raw-token checks completed.

## Merge Readiness

PR #23 can move from draft to ready only when:

- All automated checks are green.
- Hardware and mobile manual smoke are complete.
- No stop-gate is active.
- No real PII, secrets, logs, DB dumps or runtime artifacts are in PR.
- ACCESS-002 issue has factual results attached.

## Required Evidence

Attach to Issue #24 or PR #23:

- device/browser used;
- scanner model;
- DEV URL used;
- pass/fail table for allowed/denied matrix;
- pass/fail table for mobile scanner;
- pass/fail table for HID scanner;
- screenshots only with synthetic data;
- list of confirmed UX defects and fixes.

## Non-Goals

- No production rollout.
- No real turnstile integration.
- No biometric access.
- No real personal data import.

## ACCESS-002.3 stop-gate notes

PR #23 stays draft until the updated opaque CP2 QR is decoded on Xiaomi 11T Pro and by the HID scanner. The old long signed payload remains a compatibility branch only; new mobile passes must show the short opaque CP2 token.
## ACCESS-002.4 readiness additions

- [ ] Replayed CP2 denied event displays known owner and entity label when the token belongs to a known person.
- [ ] Russian keyboard HID normalization accepts only strict CP2 tokens and records `layout_normalized` in sanitized access audit metadata.
- [ ] `/access/mobile-scanner` renders without a blank page when `BarcodeDetector` is unavailable and falls back to local `jsQR`.
- [ ] `/admin/users` can save DEV-only `*@local` test accounts without native browser email validation blocking the form.
