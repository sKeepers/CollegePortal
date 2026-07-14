# CollegePortal Gateway Windows Installation

Target: Windows 7, .NET Framework 4.8, Windows Service, no Docker, no IIS.

Install path: `C:\CollegePortalGateway`.
Service name: `CollegePortalGateway`.
Display name: `CollegePortal Gateway`.

Use the package `releases/collegeportal-gateway-<version>.zip` and run `packaging\windows\install-all.cmd` from an elevated command prompt. The script creates directories, preserves existing private config, installs the service and checks `/health` and `/version`.

Do not install to `C:\CollegePortalFisGateway` for new deployments. If an old `CollegePortalFisGateway` service exists, stop it and migrate config manually after reviewing secrets.

Firewall must allow only Linux DEV `192.168.34.104` to the Gateway port. Do not disable Windows Firewall or ViPNet.
