# UAT Server

This document records the UAT server used for INFRA-008 installer acceptance.

## Server

- Role: clean UAT validation server
- Hostname: `srv-portal`
- IP: `192.168.34.17`
- SSH user: `andale`
- OS: Ubuntu 24.04.3 LTS
- Kernel: `6.8.0-134-generic`
- Architecture: `x86_64`
- Hypervisor: Microsoft Hyper-V
- CPU: 4 vCPU, Intel Xeon Gold 6226R
- RAM: 15 GiB
- Root disk: 98G ext4
- SSH fingerprint: `SHA256:1Itb3cVzunlqN2nUBa3/vBv9O1zypsDvlud++LBJ9J4`

## Installed Application

- Install path: `/opt/college-portal`
- Release archive on DEV: `/srv/college-dev/releases/college-portal-0.8.0-rc2.tar.gz`
- Release archive on UAT: `/home/andale/college-portal-release/college-portal-0.8.0-rc2-final.tar.gz`
- SHA-256: `17c360bc88043ad28bb2c5adea7020497affd422788fe94eb7c7326959fca611`
- Build: `ed499f4`
- HTTP URL: `http://192.168.34.17`
- HTTPS URL: `https://192.168.34.17`

## Secrets

Do not commit credentials, `.env`, certificates, dumps or backups.

The UAT admin password is stored only on the UAT host:

```text
/home/andale/college-portal-release/admin_credentials.txt
```

Recommended after acceptance:

- rotate the SSH password;
- disable password SSH when keys are ready;
- rotate the CollegePortal admin password before wider testing.
