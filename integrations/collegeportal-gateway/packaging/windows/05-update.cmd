@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
echo Обновление Gateway выполняется через install-all.cmd: он сохраняет private config, обновляет EXE и binPath службы.
call "%~dp0install-all.cmd"
exit /b %ERRORLEVEL%