@echo off
setlocal
set ROOT=C:\CollegePortalGateway
if not exist "%ROOT%\config\gateway.private.config" copy "%~dp0..\..\config.example" "%ROOT%\config\gateway.private.config"
echo Review %ROOT%\config\gateway.private.config manually. Secrets are never printed by this script.
