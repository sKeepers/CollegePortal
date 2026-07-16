@echo off
chcp 65001 >nul
setlocal EnableExtensions DisableDelayedExpansion
set "PACKAGE_ROOT=%~dp0."
if not exist "%PACKAGE_ROOT%\config.example" set "PACKAGE_ROOT=%~dp0..\..\."
for %%I in ("%PACKAGE_ROOT%") do set "PACKAGE_ROOT=%%~fI"
set "PACKAGE_ROOT_ARGUMENT=%PACKAGE_ROOT%"
if "%PACKAGE_ROOT:~-1%"=="\" set "PACKAGE_ROOT_ARGUMENT=%PACKAGE_ROOT%."

set "INSTALL_MODE="
if /I "%~1"=="--dry-run" (
  set "INSTALL_MODE=-PreflightOnly"
  shift
)
if not "%~1"=="" (
  echo [ОШИБКА] Неизвестный параметр: %~1
  echo Допустимый параметр: --dry-run
  exit /b 2
)

powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "%~dp0Install-Gateway.ps1" -PackageRoot "%PACKAGE_ROOT_ARGUMENT%" %INSTALL_MODE%
exit /b %errorlevel%
