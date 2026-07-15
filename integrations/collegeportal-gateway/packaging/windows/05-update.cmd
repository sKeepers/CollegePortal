@echo off
chcp 65001 >nul
echo Обновление использует тот же проверяемый repair-поток и сохраняет private config.
call "%~dp001-install.cmd"
exit /b %errorlevel%
