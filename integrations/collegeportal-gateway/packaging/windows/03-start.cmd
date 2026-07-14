@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set SERVICE=CollegePortalGateway
set EXE=%ROOT%\bin\CollegePortal.Gateway.Host.exe
set CFG=%ROOT%\config\gateway.private.config

echo [4/5] Запуск службы %SERVICE%...
if not exist "%EXE%" (
  echo ОШИБКА: executable не найден: %EXE%
  exit /b 1
)
if not exist "%CFG%" (
  echo ОШИБКА: конфигурация не найдена: %CFG%
  exit /b 1
)

sc qc "%SERVICE%" | findstr /I "CollegePortal.Gateway.Host.exe" >nul || (
  echo ОШИБКА: служба указывает не на CollegePortal.Gateway.Host.exe
  sc qc "%SERVICE%"
  exit /b 1
)

net start "%SERVICE%"
if errorlevel 1 (
  echo ОШИБКА: служба не запустилась. Состояние:
  sc query "%SERVICE%"
  echo Проверьте путь executable и конфигурацию gateway.private.config.
  exit /b 1
)

echo OK: служба запущена.
exit /b 0
