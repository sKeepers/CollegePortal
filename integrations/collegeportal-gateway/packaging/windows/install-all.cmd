@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set REPORT=%ROOT%\diagnostics\install-report.txt

echo CollegePortal Gateway: автоматическая установка для Windows 7
echo PROD/FIS production не используется.
echo.

call "%~dp000-check-prerequisites.cmd" || exit /b 1
call "%~dp001-install.cmd" || exit /b 1
call "%~dp002-configure.cmd" || exit /b 1
call "%~dp003-start.cmd" || exit /b 1
call "%~dp004-health.cmd" || (call "%~dp011-rollback.cmd" & exit /b 1)

if not exist "%ROOT%\diagnostics" mkdir "%ROOT%\diagnostics"
(
  echo CollegePortal Gateway installation completed.
  echo Date: %DATE% %TIME%
  echo Install root: %ROOT%
  sc qc CollegePortalGateway
) > "%REPORT%"

echo.
echo Установка завершена успешно.
echo Отчет: %REPORT%
exit /b 0
