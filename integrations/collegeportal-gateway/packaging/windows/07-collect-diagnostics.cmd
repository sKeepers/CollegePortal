@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set OUT=%ROOT%\diagnostics\gateway-diagnostics.txt
if not exist "%ROOT%\diagnostics" mkdir "%ROOT%\diagnostics"

echo [12/12] Сбор диагностики Gateway
(
  echo Диагностика CollegePortal Gateway
  echo Дата: %DATE% %TIME%
  echo.
  echo === Версия Windows ===
  ver
  echo.
  echo === .NET Framework ===
  reg query "HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full" /v Release
  echo.
  echo === VERSION Gateway ===
  if exist "%ROOT%\VERSION" type "%ROOT%\VERSION" else echo VERSION не найден
  echo.
  echo === Служба ===
  sc query CollegePortalGateway
  echo.
  sc qc CollegePortalGateway
  echo.
  echo === Firewall ===
  netsh advfirewall firewall show rule name="CollegePortal Gateway"
  echo.
  echo === Порт 8099 ===
  netstat -ano ^| findstr ":8099"
  echo.
  echo === HTTP /version ===
  powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $wc=New-Object Net.WebClient; $wc.DownloadString('http://127.0.0.1:8099/version') } catch { $_.Exception.Message }"
  echo.
  echo === Доступность ФИС TEST ===
  powershell -NoProfile -ExecutionPolicy Bypass -Command "try { $wc=New-Object Net.WebClient; $wc.DownloadString('http://10.0.3.1:8383/api/import/ImportService.svc?wsdl') | Out-Null; 'ФИС TEST отвечает на ?wsdl' } catch { $_.Exception.Message }"
  echo.
  echo === Наличие WSDL/XSD ===
  dir "%ROOT%\specs\fis\discovered"
  echo.
  echo === Последние строки audit.log ===
  if exist "%ROOT%\logs\audit.log" powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Content -LiteralPath '%ROOT%\logs\audit.log' -Tail 30" else echo audit.log не найден
) > "%OUT%" 2>&1

echo Диагностика записана в %OUT%.
echo Перед отправкой проверьте файл и удалите секреты, если они случайно попали в отчет.
exit /b 0