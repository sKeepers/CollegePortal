@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set URL=http://127.0.0.1:8099

echo [10/12] Проверка HTTP endpoints Gateway
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $wc=New-Object Net.WebClient; foreach($p in @('/health','/version','/adapters','/adapters/fis/health')) { try { $r=$wc.DownloadString('%URL%'+$p); Write-Host ('OK '+$p+' '+$r) } catch { Write-Host ('ОШИБКА '+$p+' '+$_.Exception.Message); exit 1 } }"
if errorlevel 1 (
  echo ОШИБКА: Gateway не прошел health-проверку.
  echo URL: %URL%
  echo Команда: PowerShell Net.WebClient DownloadString
  exit /b 1
)
echo OK: /health, /version, /adapters и /adapters/fis/health проверены.
exit /b 0