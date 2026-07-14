@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set OUT=%ROOT%\diagnostics\collegeportal-gateway-diagnostics.txt
if not exist "%ROOT%\diagnostics" mkdir "%ROOT%\diagnostics"

(
  echo CollegePortal Gateway diagnostics
  echo Date: %DATE% %TIME%
  echo.
  sc query CollegePortalGateway
  echo.
  sc qc CollegePortalGateway
  echo.
  powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $wc=New-Object Net.WebClient; $wc.DownloadString('http://127.0.0.1:8099/version') } catch { $_.Exception.Message }"
  echo.
  route print 10.0.3.1
) > "%OUT%"

echo Диагностика записана в %OUT%. Перед отправкой проверьте и удалите секреты.
exit /b 0
