@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
echo Остановка службы CollegePortalGateway
net stop CollegePortalGateway
exit /b %ERRORLEVEL%