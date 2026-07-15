@echo off
chcp 65001 >nul
setlocal
:menu
cls
echo ================================================================
echo  CollegePortal Gateway - меню оператора
echo ================================================================
echo  1. Предварительная проверка пакета
echo  2. Установить или восстановить службу
echo  3. Запустить службу и проверить health
echo  4. Остановить службу
echo  5. Собрать безопасную диагностику
echo  6. Скачать WSDL/XSD/DISCO из ФИС TEST
echo  7. Проверить и импортировать private-контракт
echo  8. Открыть private config
echo  9. Rollback бинарных файлов
echo  0. Выход
echo.
set /p CHOICE=Выберите действие:
if "%CHOICE%"=="1" call "%~dp000-check-prerequisites.cmd"
if "%CHOICE%"=="2" call "%~dp001-install.cmd"
if "%CHOICE%"=="3" call "%~dp003-start.cmd"
if "%CHOICE%"=="4" call "%~dp006-stop.cmd"
if "%CHOICE%"=="5" call "%~dp007-collect-diagnostics.cmd"
if "%CHOICE%"=="6" call "%~dp008-download-fis-contract.cmd"
if "%CHOICE%"=="7" call "%~dp009-import-fis-contract.cmd"
if "%CHOICE%"=="8" call "%~dp002-configure.cmd"
if "%CHOICE%"=="9" call "%~dp011-rollback.cmd"
if "%CHOICE%"=="0" exit /b 0
echo.
pause
goto menu
