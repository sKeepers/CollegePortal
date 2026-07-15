@echo off
chcp 65001 >nul
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Collect-GatewayDiagnostics.ps1" -InstallRoot "C:\CollegePortalGateway"
exit /b %errorlevel%
