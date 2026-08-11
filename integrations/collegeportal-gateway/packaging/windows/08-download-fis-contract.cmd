@echo off
chcp 65001 >nul
echo ВНИМАНИЕ: выполняется только скачивание метаданных ФИС TEST :8383. SOAP и Import не вызываются.
powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -File "%~dp0Download-FisContract.ps1" -InstallRoot "C:\CollegePortalGateway"
exit /b %errorlevel%
