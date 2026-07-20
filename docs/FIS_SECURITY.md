# FIS Security

## Boundaries

- TEST endpoint only: `10.0.3.1:8383`.
- Production endpoint `10.0.3.1:8080` is blocked.
- Import/Validate/Delete are disabled in GIA-003.
- Credentials are loaded only from private configuration on the Gateway side.

## XML Safety

- SOAP envelopes are rejected.
- DOCTYPE is rejected to prevent XXE.
- XML parsing disables external network access.
- XSD validation is required before future mutating package transfer.

## Logging

FIS communication logs contain only operational metadata:

- timestamp;
- method;
- request id;
- duration;
- status;
- HTTP code;
- redacted error/fault code or hash.

Raw XML, credentials and personal data must not be stored in logs, GitHub issues, PRs or documentation.
