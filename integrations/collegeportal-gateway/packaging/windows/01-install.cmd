@echo off
setlocal
set ROOT=C:\CollegePortalGateway
call "%~dp000-check-prerequisites.cmd" || exit /b 1
for %%D in ("%ROOT%" "%ROOT%\bin" "%ROOT%\config" "%ROOT%\logs" "%ROOT%\cache" "%ROOT%\updates" "%ROOT%\backup" "%ROOT%\diagnostics" "%ROOT%\specs") do if not exist %%D mkdir %%D
if exist "%ROOT%\config\gateway.private.config" (echo Existing private config preserved.) else copy "%~dp0..\..\config.example" "%ROOT%\config\gateway.private.config" >nul
xcopy /Y /I "%~dp0..\..\bin\*" "%ROOT%\bin\" >nul 2>&1
sc query CollegePortalGateway >nul 2>&1 || sc create CollegePortalGateway binPath= "\"%ROOT%\bin\CollegePortal.Gateway.exe\" --config \"%ROOT%\config\gateway.private.config\"" start= demand
sc description CollegePortalGateway "CollegePortal Gateway for protected integrations"
echo Installed CollegePortalGateway in %ROOT%.
