@echo off
chcp 866 >nul
setlocal
set URL=http://127.0.0.1:8099

echo [5/5] Проверка health endpoint...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $wc=New-Object Net.WebClient; $h=$wc.DownloadString('%URL%/health'); $v=$wc.DownloadString('%URL%/version'); Write-Host $h; Write-Host $v" || (
  echo ОШИБКА: Gateway не отвечает на %URL%.
  exit /b 1
)

echo OK: Gateway отвечает на /health и /version.
exit /b 0
