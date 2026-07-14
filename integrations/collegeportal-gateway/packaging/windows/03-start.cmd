@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set SERVICE=CollegePortalGateway
set EXE=%ROOT%\bin\CollegePortal.Gateway.Host.exe
set CFG=%ROOT%\config\gateway.private.config

echo [9/12] Запуск службы %SERVICE%
if not exist "%EXE%" (
  echo ОШИБКА: executable не найден.
  echo Файл: %EXE%
  exit /b 1
)
if not exist "%CFG%" (
  echo ОШИБКА: конфигурация не найдена.
  echo Файл: %CFG%
  exit /b 1
)
sc qc "%SERVICE%" | findstr /I "CollegePortal.Gateway.Host.exe" >nul
if errorlevel 1 (
  echo ОШИБКА: служба указывает не на CollegePortal.Gateway.Host.exe.
  echo Текущая конфигурация:
  sc qc "%SERVICE%"
  exit /b 1
)
net stop "%SERVICE%" >nul 2>&1
net start "%SERVICE%"
if errorlevel 1 (
  echo ОШИБКА: служба не запустилась.
  echo Команда: net start %SERVICE%
  echo Код ошибки: !ERRORLEVEL!
  echo Состояние службы:
  sc query "%SERVICE%"
  echo Конфигурация службы:
  sc qc "%SERVICE%"
  exit /b 1
)
echo OK: служба запущена.
exit /b 0