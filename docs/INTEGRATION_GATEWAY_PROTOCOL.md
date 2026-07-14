# CollegePortal Gateway Protocol

## Canonical request

Signed requests use HMAC-SHA256 over:

```text
HTTP_METHOD
PATH
TIMESTAMP
NONCE
BODY_SHA256
```

Headers:

- `X-Gateway-Request-Id`
- `X-Gateway-Timestamp` in UTC `YYYY-MM-DDTHH:MM:SSZ`
- `X-Gateway-Nonce`
- `X-Gateway-Body-SHA256`
- `X-Gateway-Signature`

For temporary compatibility, FIS-GATEWAY-001 `X-FIS-*` headers are also accepted. New integrations must use `X-Gateway-*`.

## Security controls

Gateway enforces IP allowlist, request TTL, replay protection, request body limit, rate limit, constant-time signature comparison and redacted diagnostics. Secrets are stored only in `/srv/college-dev/.secrets/collegeportal-gateway.env` on Linux DEV and `C:\CollegePortalGateway\config\gateway.private.config` on Windows.

Secrets, SOAP bodies, credentials and personal data must never appear in diagnostics, logs, GitHub Issues or Markdown.
