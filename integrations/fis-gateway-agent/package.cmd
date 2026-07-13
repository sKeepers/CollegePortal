@echo off
setlocal
set ROOT=%~dp0
call "%ROOT%build.cmd" || exit /b 1
powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%package.ps1"
