@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set CFG=%ROOT%\config\gateway.private.config

echo [8/12] Проверка конфигурации Gateway
if not exist "%CFG%" (
  echo ОШИБКА: конфигурация не найдена.
  echo Файл: %CFG%
  exit /b 1
)
findstr /I "SharedSecret=CHANGE_ME" "%CFG%" >nul 2>&1
if not errorlevel 1 (
  echo ВНИМАНИЕ: SharedSecret содержит значение CHANGE_ME.
  echo Перед подключением CollegePortal замените секрет в файле: %CFG%
)
findstr /I "FisProductionEnabled=true" "%CFG%" >nul 2>&1
if not errorlevel 1 (
  echo ОШИБКА: FisProductionEnabled=true запрещен для DEV/TEST Gateway.
  echo Файл: %CFG%
  exit /b 1
)
findstr /I "FisTestEndpoint=http://10.0.3.1:8383" "%CFG%" >nul 2>&1
if errorlevel 1 (
  echo ВНИМАНИЕ: тестовый endpoint ФИС отличается от ожидаемого 10.0.3.1:8383.
)
echo OK: конфигурация проверена. Секреты не выводятся.
exit /b 0