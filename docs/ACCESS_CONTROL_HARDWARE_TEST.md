# ACCESS-002: Hardware Smoke Checklist

## Scope

This checklist validates the Access Control module with a real HID 2D scanner. It is a manual acceptance artifact for PR #23 and must be executed only in DEV or another non-production environment.

Do not use PROD. Do not use real personal data unless a separate approval exists. Prefer UAT/demo users with synthetic names.

## Test Environment

| Field | Value |
| --- | --- |
| Portal URL | `https://192.168.34.104:5443` or `https://college-dev.local:5443` |
| HTTP diagnostic URL | `http://192.168.34.104:5174` only for non-camera diagnostics |
| Backend API | same origin `/api` through HTTPS proxy |
| Branch / PR | `feature/access-control-foundation`, PR #23 |
| Scanner model | Fill during test |
| Workstation OS | Fill during test |
| Operator role | `access_operator`, `security`, or `access_admin` |
| Student test account | Synthetic data only |

## Preconditions

- [ ] Database migrations are applied.
- [ ] `RoleSeeder` has run and access permissions exist.
- [ ] Test student has a linked `Person`.
- [ ] Operator can open `/access/checkpoint`.
- [ ] Operator has `access.scan`.
- [ ] Operator without `access.override` cannot use manual override.
- [ ] Admin/operator with `access.override` must provide a reason for override.
- [ ] No production data is used.

## HID Scanner Setup

- [ ] Scanner is detected by OS as HID keyboard.
- [ ] Scanner sends Enter suffix after QR text.
- [ ] Scanner does not add prefix text.
- [ ] Scanner reads short opaque CP2 token without truncation.
- [ ] Russian keyboard layout does not corrupt ASCII token characters.
- [ ] Caps Lock and Num Lock do not change scanned token.
- [ ] If Enter suffix is absent, document scanner model and required configuration barcode. Do not weaken parser globally.

## `/access/checkpoint` Smoke

- [ ] Open `/access/checkpoint`.
- [ ] Input focus is active before scan.
- [ ] Scan valid CP2 token.
- [ ] Large allowed status is shown.
- [ ] Person photo or placeholder is visible.
- [ ] Name is readable and belongs to the synthetic test person.
- [ ] Group or department is short and readable.
- [ ] Direction is visible.
- [ ] Timestamp is visible.
- [ ] Focus returns automatically after result.
- [ ] Scanner can scan next QR without mouse click.

## Duplicate And Replay

- [ ] Fast duplicate scan of legacy CP1/plain token returns the original event with duplicate ignored behavior.
- [ ] CP2 token replay is denied with `replayed_token`.
- [ ] Used CP2 token cannot be accepted by scanning again.
- [ ] Expired CP2 token is denied with `expired_token`.
- [ ] Malformed QR is denied.
- [ ] Tampered signature is denied with `invalid_signature`.

## Entry / Exit Matrix

| Step | Input | Expected |
| --- | --- | --- |
| 1 | valid unused CP2, entry | allowed |
| 2 | new CP2, entry again | denied `duplicate_direction` |
| 3 | new CP2, exit | allowed |
| 4 | new CP2, exit again | denied `duplicate_direction` |

## Denied Scenarios

| Scenario | Expected result |
| --- | --- |
| expired CP2 | denied, Russian message, audit event |
| replayed CP2 | denied, Russian message, audit event |
| invalid signature | denied, Russian message, audit event |
| malformed QR | denied, Russian message, audit event |
| blocked/inactive identity | denied, Russian message, audit event |
| insufficient operator permission | HTTP 403 |
| rate limit exceeded | HTTP 429 |

## Audit And Privacy

- [ ] `access_events` has event with direction, result, access point, device and request id.
- [ ] `access_audit_events` has scan event.
- [ ] `audit_logs` has sanitized scan action.
- [ ] No raw CP2 token is present in `access_pass_tokens`.
- [ ] No raw CP2 token is present in application logs.
- [ ] QR payload contains no full name, group, phone, email, address, documents, SNILS or passport data.

## Stop-Gates

Stop and do not merge if any of these happen:

- Scanner loses or changes characters.
- Scanner cannot provide Enter suffix and no safe device-specific setup instruction is available.
- CP2 replay is accepted.
- Raw token appears in DB, logs or audit.
- RBAC can be bypassed.
- Real personal data is required for the test.
