@echo off
chcp 65001 >nul
setlocal
set "CONFIG=C:\CollegePortalGateway\config\gateway.private.config"
if not exist "%CONFIG%" (
  echo [ОШИБКА] Private config не найден. Сначала выполните install-all.cmd.
  exit /b 1
)
echo Открывается private config. Не копируйте secret в Issues, Git или логи.
notepad.exe "%CONFIG%"
