@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
echo Удаление службы CollegePortalGateway. Каталог C:\CollegePortalGateway не удаляется автоматически.
net stop CollegePortalGateway >nul 2>&1
sc delete CollegePortalGateway
exit /b %ERRORLEVEL%