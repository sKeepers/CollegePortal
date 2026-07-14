# CollegePortal Gateway Update

Updates are manual and explicit. `05-update.cmd` documents the safe sequence: download ZIP, verify SHA-256, back up binaries, stop service, replace binaries, preserve config/secrets/logs, start service and verify `/health`.

Automatic background updates are intentionally not implemented for the Windows 7 ViPNet workstation.
