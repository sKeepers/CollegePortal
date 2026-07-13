# ADR: CollegePortal Integration Gateway

## Status

Accepted for Release 0.9 foundation.

## Context

Some external systems, including FIS TEST through ViPNet/ZKSPD, are not reachable directly from Linux DEV or require protected Windows workstations. The earlier FIS Gateway Agent solved only the first FIS path and used FIS-specific naming.

## Decision

Rename and modularize the agent as CollegePortal Gateway. Keep Windows 7 and .NET Framework 4.8 compatibility, expose a small adapter model, preserve the FIS adapter, keep production disabled and support old FIS routes temporarily as deprecated aliases.

## Consequences

Portal code can grow toward multiple adapters without duplicating HMAC/IP allowlist/replay controls. Windows installation now uses `C:\CollegePortalGateway` and service name `CollegePortalGateway`. The real ViPNet installation remains a separate task.
