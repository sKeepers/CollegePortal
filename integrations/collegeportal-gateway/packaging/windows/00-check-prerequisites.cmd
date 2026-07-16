@echo off
chcp 65001 >nul
setlocal
set "PACKAGE_ROOT=%~dp0"
if not exist "%PACKAGE_ROOT%config.example" set "PACKAGE_ROOT=%~dp0..\..\"
powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "%~dp0Install-Gateway.ps1" -PackageRoot "%PACKAGE_ROOT%" -PreflightOnly
exit /b %errorlevel%
