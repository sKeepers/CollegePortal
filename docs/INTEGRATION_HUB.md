# Integration Hub

Integration Hub is the CollegePortal boundary for systems that are unavailable from the Linux DEV server or require a protected workstation. The first concrete implementation is CollegePortal Gateway on the ViPNet workstation for FIS GIA and Admissions TEST access.

## Current scope

- CollegePortal Gateway runs as a Windows service on Windows 7 with .NET Framework 4.8.
- The first adapter is `fis`.
- Future adapters are planned for FRDO, Moodle, LDAP/Active Directory, MAX, Telegram, Email and access-control systems.
- Production FIS `:8080` remains disabled.

## Runtime flow

CollegePortal Laravel calls CollegePortal Gateway through signed HTTP requests. Gateway validates IP allowlist, HMAC, timestamp, nonce, body hash, request size and rate limits, then dispatches to the selected adapter.

## DEV endpoint planning

Linux DEV primary address is `192.168.34.104` on `eth0`. This address is the intended allowlisted Portal client for the Gateway service.
