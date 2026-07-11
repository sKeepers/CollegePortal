# Installation Acceptance Test - CollegePortal 0.8.0-rc1

Date: 2026-07-12
Status: BLOCKED

## Goal

Validate the CollegePortal installer distribution on a separate clean Ubuntu Server 24.04 LTS VM and confirm the lifecycle:

install -> use -> backup -> update -> restore -> uninstall -> reinstall.

## Release Artifact

Expected artifact: `college-portal-0.8.0-rc1.tar.gz`

Expected SHA-256:

```text
ed6aa78aafbf53d76b3a1ca50ae8f142be87b3fbf71b8624b61387c1180717f8
```

DEV artifact location checked:

```text
/srv/college-dev/releases/college-portal-0.8.0-rc1.tar.gz
```

Observed SHA-256 on DEV:

```text
ed6aa78aafbf53d76b3a1ca50ae8f142be87b3fbf71b8624b61387c1180717f8
```

Checksum status: PASS.

## VM Discovery

The acceptance test requires a separate clean VM. The current known DEV host is not suitable for install acceptance because it already hosts `/srv/college-dev` and running DEV containers.

Known DEV host:

```text
hostname: moodle
ip: 192.168.34.104
role: DEV host
```

Network/host discovery results:

- Hyper-V PowerShell module is available, but `Get-VM` did not return an accessible clean VM for this test run.
- LAN ARP scan found several hosts in `192.168.34.0/24`.
- SSH port 22 was reachable on `192.168.34.104` and `192.168.34.219`.
- `192.168.34.104` is the existing DEV host and was not used for installation acceptance.
- `192.168.34.219` offered only legacy `ssh-rsa`; compatibility connection was attempted, but the available `andale` credentials were rejected.

## Acceptance Stages

| Stage | Result | Notes |
| --- | --- | --- |
| Clean VM inventory | BLOCKED | No accessible clean Ubuntu Server 24.04 VM was available. |
| Archive transfer | NOT RUN | Requires target VM. |
| SHA-256 verification on VM | NOT RUN | DEV artifact checksum was verified only on DEV. |
| Clean install | NOT RUN | Running install over DEV is explicitly forbidden. |
| Frontend/API/login checks | NOT RUN | Requires installed acceptance instance. |
| Clean DB checks | NOT RUN | Requires installed acceptance instance. |
| Minimal data creation | NOT RUN | Requires installed acceptance instance. |
| Backup | NOT RUN | Requires installed acceptance instance. |
| Update | NOT RUN | Requires installed acceptance instance. |
| Failed update / rollback | NOT RUN | Requires installed acceptance instance. |
| Restore | NOT RUN | Requires installed acceptance instance. |
| Repeated install protection | NOT RUN | Requires installed acceptance instance. |
| check.sh | NOT RUN | Requires installed acceptance instance. |
| HTTPS | NOT RUN | Requires installed acceptance instance. |
| Security acceptance | NOT RUN | Requires installed acceptance instance. |
| Uninstall | NOT RUN | Requires installed acceptance instance. |
| Reinstall | NOT RUN | Requires installed acceptance instance. |

## Blocking Issue

Acceptance testing cannot proceed until a separate clean VM is available with:

- Ubuntu Server 24.04 LTS amd64;
- 4 vCPU;
- 8 GB RAM;
- 60 GB disk;
- separate IP address;
- sudo/root access;
- reachable SSH credentials.

## Required Inputs To Resume

Provide one of the following:

1. IP address, SSH username and password/key for a clean Ubuntu Server 24.04 VM.
2. Permission and source ISO/cloud image details to create a new clean Hyper-V VM.
3. Confirmation that a specific existing host may be wiped and used as the clean VM.

## Release Decision

No release promotion decision can be made from this run.

Current decision: FAIL / BLOCKED due unavailable acceptance VM, not due installer failure.

## Notes

The installer artifact itself exists on DEV and its checksum matches the expected SHA-256. No install, update, restore, uninstall or security acceptance actions were performed against DEV or PROD.
