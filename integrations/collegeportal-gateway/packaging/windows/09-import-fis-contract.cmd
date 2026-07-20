@echo off
chcp 65001 >nul
echo Импорт создает private-анализ, но НЕ разрешает live XML-over-HTTP вызовы автоматически.
powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "%~dp0Import-FisContract.ps1" -InstallRoot "C:\CollegePortalGateway"
exit /b %errorlevel%
