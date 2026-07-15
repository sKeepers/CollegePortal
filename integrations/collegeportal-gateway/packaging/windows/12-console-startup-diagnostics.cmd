@echo off
chcp 65001 >nul
setlocal EnableExtensions DisableDelayedExpansion
set "ROOT=C:\CollegePortalGateway"
set "EXE=%ROOT%\bin\CollegePortal.Gateway.Host.exe"
set "CONFIG=%ROOT%\config\gateway.private.config"
set "STARTUP_LOG=%ROOT%\logs\startup.log"

echo ================================================================
echo  CollegePortal Gateway - диагностика запуска в консоли
echo ================================================================
echo.

sc.exe query CollegePortalGateway | findstr /C:"STATE" /C:"RUNNING"
sc.exe query CollegePortalGateway | findstr /C:"RUNNING" >nul
if not errorlevel 1 goto service_running

if not exist "%EXE%" goto binary_missing
if not exist "%CONFIG%" goto config_missing

cd /d "%ROOT%\bin" || goto working_directory_failed
echo [INFO] Запуск EXE в console mode. Для остановки после успешного старта нажмите Ctrl+C.
echo [INFO] Полная диагностика записывается в %STARTUP_LOG%
echo.
"%EXE%" --console --config "%CONFIG%"
set "RESULT=%ERRORLEVEL%"
echo.
echo [INFO] Gateway завершился с кодом %RESULT%.
echo [INFO] Передайте startup.log и результат 07-collect-diagnostics.cmd разработчику.
exit /b %RESULT%

:service_running
echo [STOP-GATE] Служба уже запущена. Остановите ее через 06-stop.cmd перед console diagnostics.
exit /b 20

:binary_missing
echo [BINARY_MISSING] Не найден %EXE%
exit /b 4

:config_missing
echo [CONFIG_NOT_FOUND] Не найден %CONFIG%
exit /b 2

:working_directory_failed
echo [STARTUP_FAILED] Не удалось перейти в %ROOT%\bin
exit /b 10
