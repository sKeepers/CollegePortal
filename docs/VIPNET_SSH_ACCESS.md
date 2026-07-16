# ViPNet-PC SSH access for CollegePortal Gateway

## Purpose

This document describes the safe DEV-only SSH/SCP channel from the Codex workstation to the ViPNet-PC used for CollegePortal Gateway diagnostics and controlled package transfer.

Scope:

- host: `192.168.34.223`;
- SSH alias: `college-vipnet`;
- Windows account: `Администратор`;
- Gateway installation directory: `C:\CollegePortalGateway`;
- Gateway service: `CollegePortalGateway`;
- Gateway API port: `8099`;
- FIS TEST only: `10.0.3.1:8383`.

FIS production `:8080`, Import, Validate and Delete operations are forbidden in this workflow.

## Security rules

- Do not commit private keys, HMAC secrets, credentials, private config, WSDL/XSD/DISCO files, raw SOAP bodies or logs.
- Do not copy the SSH private key to the ViPNet-PC.
- Do not clone the full CollegePortal repository to the ViPNet-PC.
- Use the Gateway ZIP package only.
- Run installation only after a green service-installation smoke test and separate operator confirmation.

## SSH configuration

The local user SSH config should contain a `college-vipnet` host alias with:

```sshconfig
Host college-vipnet
    HostName 192.168.34.223
    User Администратор
    Port 22
    IdentityFile C:\Users\admin\.ssh\id_ed25519
    IdentitiesOnly yes
```

Do not print the full SSH config in task reports if it contains other hosts.

For administrator accounts, OpenSSH uses:

```text
C:\ProgramData\ssh\administrators_authorized_keys
```

because `sshd_config` contains `Match Group administrators`.

## Automation scripts

Run from the repository worktree:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\infrastructure\windows\test-vipnet-ssh.ps1 -HostAlias college-vipnet
```

Checks:

- public key fingerprint;
- passwordless SSH;
- remote hostname and user;
- `sshd.exe -t`;
- `administrators_authorized_keys` existence and ACL;
- SCP upload/read/delete smoke test.

Collect read-only Gateway diagnostics:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\infrastructure\windows\collect-gateway-diagnostics.ps1 -HostAlias college-vipnet
```

The collector does not read `gateway.private.config` and rejects output with sensitive markers.

Verify a Gateway ZIP before any transfer:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\infrastructure\windows\deploy-gateway-test.ps1 `
  -PackagePath releases\<ci-run>\<artifact>\collegeportal-gateway-0.2.6-dev.zip `
  -ExpectedSha256 <sha256>
```

Default mode is dry-run. Use `-Copy` only to transfer a verified package. `-Install` is intentionally blocked until installation automation has a separate approved workflow.

## Current DEV checkpoint

As of the first successful SSH setup:

- passwordless SSH works;
- SCP works;
- local public key fingerprint and server authorized key fingerprint match;
- `sshd.exe -t` returns `0`;
- `C:\ProgramData\ssh\administrators_authorized_keys` exists;
- `CollegePortalGateway` service is not installed;
- TCP `8099` is not listening;
- `C:\CollegePortalGateway\diagnostics\installation-report.txt` shows installer stop-gate at ACL setup for private config with code `1332`.

The next Gateway task should fix that installer defect before attempting ViPNet installation again.
