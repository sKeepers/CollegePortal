@echo off
chcp 65001 >nul
net start CollegePortalGateway
if errorlevel 1 exit /b %errorlevel%
call "%~dp004-health.cmd"
