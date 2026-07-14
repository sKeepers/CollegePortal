@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
:menu
cls
echo =====================================================
echo CollegePortal Gateway - русское меню обслуживания
echo =====================================================
echo 1  Установить Gateway
echo 2  Обновить Gateway
echo 3  Запустить службу
echo 4  Остановить службу
echo 5  Проверить состояние
echo 6  Скачать WSDL/XSD
echo 7  Импортировать контракт
echo 8  Проверить соединение с ФИС
echo 9  Диагностика
echo 10 Удалить Gateway
echo 11 Откат
echo 12 Проверить русскую кодировку
echo 0  Выход
echo.
set /p CHOICE=Выберите пункт: 
if "%CHOICE%"=="1" call "%~dp0install-all.cmd"
if "%CHOICE%"=="2" call "%~dp005-update.cmd"
if "%CHOICE%"=="3" call "%~dp003-start.cmd"
if "%CHOICE%"=="4" call "%~dp006-stop.cmd"
if "%CHOICE%"=="5" call "%~dp004-health.cmd"
if "%CHOICE%"=="6" call "%~dp008-download-fis-contract.cmd"
if "%CHOICE%"=="7" call "%~dp009-import-fis-contract.cmd"
if "%CHOICE%"=="8" call "%~dp004-health.cmd"
if "%CHOICE%"=="9" call "%~dp007-collect-diagnostics.cmd"
if "%CHOICE%"=="10" call "%~dp010-uninstall.cmd"
if "%CHOICE%"=="11" call "%~dp011-rollback.cmd"
if "%CHOICE%"=="12" call "%~dp099-test-russian-encoding.cmd"
if "%CHOICE%"=="0" exit /b 0
echo.
pause
goto menu