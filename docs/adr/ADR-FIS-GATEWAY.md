# ADR: FIS ViPNet Gateway Agent

Date: 2026-07-13
Status: Accepted for DEV implementation

## Context

CollegePortal DEV and the backend container do not have direct TCP access to FIS TEST `10.0.3.1:8383`. Access is available only from a separate ViPNet workstation (`192.168.34.223`, Windows 7). Credentials and FIS-specific authentication must remain on that protected workstation.

## Decision

Use a dedicated FIS Gateway Agent on the ViPNet workstation:

```text
CollegePortal backend -> protected internal Gateway API -> Gateway Agent on ViPNet PC -> SOAP/WCF -> FIS TEST 10.0.3.1:8383
```

The agent is a separate integration adapter, not a generic Integration Hub platform. It is built for .NET Framework 4.8 and self-hosts HTTP with `HttpListener` to remain compatible with Windows 7 without Docker or IIS.

## Security

Portal-to-Agent requests use IP allowlist, HMAC-SHA256, timestamp, nonce, body SHA-256, request id, replay protection, request size limit, rate limiting and structured redacted audit. Shared secrets stay in private config and are not stored in Git or system settings.

Production FIS endpoint `10.0.3.1:8080` is blocked.

## Consequences

Only safe TEST read-only operations are enabled first. `DoValidate`, `DoImport`, `DoImportApplicationSingle` and `DoDelete` remain disabled until the official application XSD, authentication mechanism and controlled TEST call are confirmed.
