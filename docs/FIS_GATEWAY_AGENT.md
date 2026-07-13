# FIS Gateway Agent

## Why It Is Needed

DEV `/srv/college-dev` currently has no TCP access to `10.0.3.1:8383`. If only a separate college workstation/server is connected to ZKSPD, CollegePortal must not receive or store FIS credentials directly. Use a local Gateway Agent on that machine.

```text
CollegePortal DEV
-> internal protected API
-> FIS Gateway Agent on ZKSPD node
-> FIS TEST 10.0.3.1:8383
```

## Security

- TEST credentials stay only on the gateway host.
- Production endpoint `:8080` is disabled.
- Portal-to-Agent authentication: bearer token for MVP, mTLS recommended before production-like use.
- Agent allowlists Portal IPs.
- Idempotency key and request id are required before send is enabled.
- Audit must record only package id, hashes, statuses and redacted metadata.
- XML with personal data must stay in private storage and never in GitHub.

## Files

- `scripts/fis/gateway-agent/agent.php`
- `scripts/fis/gateway-agent/.env.example`
- `scripts/fis/gateway-agent/README.md`

## Current Status

The agent currently supports:

- `GET /health`
- `POST /zkspd/check`
- blocked `/fis/test/send`
- blocked `/fis/test/status`

Actual SOAP send/status must be implemented only after official WSDL/XSD/spec 4.9 is loaded.

## FIS-GATEWAY-001: Windows Agent

The target agent source is now under `integrations/fis-gateway-agent/` and targets .NET Framework 4.8 for Windows 7 compatibility. Portal-to-Agent requests use HMAC-SHA256 over `HTTP method`, `path`, `timestamp`, `nonce` and `body SHA-256`. The agent keeps dangerous TEST write operations disabled until the official application XSD and authentication contract are confirmed.
