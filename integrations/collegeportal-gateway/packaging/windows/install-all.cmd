@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set REPORT=%ROOT%\diagnostics\install.log
if not exist "%ROOT%\diagnostics" mkdir "%ROOT%\diagnostics" >nul 2>&1

echo CollegePortal Gateway: автоматическая установка для Windows 7
echo PROD и ФИС production :8080 не используются.
echo Лог установки: %REPORT%
echo.

(
  echo Установка CollegePortal Gateway
  echo Дата: %DATE% %TIME%
  echo Пакет: %~dp0..\..
  echo.
) > "%REPORT%"

call "%~dp000-check-prerequisites.cmd" >> "%REPORT%" 2>&1
if errorlevel 1 goto fail
call "%~dp001-install.cmd" >> "%REPORT%" 2>&1
if errorlevel 1 goto fail
call "%~dp002-configure.cmd" >> "%REPORT%" 2>&1
if errorlevel 1 goto fail
call "%~dp003-start.cmd" >> "%REPORT%" 2>&1
if errorlevel 1 goto fail
call "%~dp004-health.cmd" >> "%REPORT%" 2>&1
if errorlevel 1 goto fail
call "%~dp007-collect-diagnostics.cmd" >> "%REPORT%" 2>&1

echo Установка завершена успешно.
echo Лог: %REPORT%
echo Диагностика: %ROOT%\diagnostics\gateway-diagnostics.txt
exit /b 0

:fail
echo.
echo ОШИБКА: установка прервана.
echo Подробности записаны в %REPORT%.
echo Последние строки лога:
powershell -NoProfile -ExecutionPolicy Bypass -Command "if(Test-Path -LiteralPath '%REPORT%'){ Get-Content -LiteralPath '%REPORT%' -Tail 40 }"
exit /b 1