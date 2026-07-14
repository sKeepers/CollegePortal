@echo off
setlocal
set ROOT=C:\CollegePortalGateway
set OUT=%ROOT%\diagnostics\collegeportal-gateway-diagnostics.txt
(sc query CollegePortalGateway & powershell -NoProfile -Command "try { Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8099/version | Select -Expand Content } catch { $_.Exception.Message }" & route print 10.0.3.1) > "%OUT%"
echo Diagnostics written to %OUT%. Review and redact before sharing.
