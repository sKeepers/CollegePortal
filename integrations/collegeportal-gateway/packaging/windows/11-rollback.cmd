@echo off
chcp 65001 >nul
powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "%~dp0Rollback-Gateway.ps1" -InstallRoot "C:\CollegePortalGateway"
exit /b %errorlevel%
