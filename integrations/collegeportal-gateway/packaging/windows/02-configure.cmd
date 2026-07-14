@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set CFG=%ROOT%\config\gateway.private.config

echo [3/5] Проверка конфигурации...
if not exist "%CFG%" (
  echo ОШИБКА: конфигурация не найдена: %CFG%
  exit /b 1
)

findstr /I "SharedSecret=CHANGE_ME" "%CFG%" >nul 2>&1 && (
  echo ВНИМАНИЕ: SharedSecret все еще содержит CHANGE_ME.
  echo Перед подключением к CollegePortal замените secret вручную.
)

findstr /I "FisProductionEnabled=true" "%CFG%" >nul 2>&1 && (
  echo ОШИБКА: FisProductionEnabled=true запрещен для этого пакета.
  exit /b 1
)

echo OK: конфигурация найдена. Секреты скрипт не выводит.
exit /b 0
