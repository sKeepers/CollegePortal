@echo off
setlocal
set ROOT=C:\CollegePortalGateway
set SRC=%ROOT%\specs\fis\discovered
set DST=%ROOT%\specs\fis\active
for %%F in (import-service.single.wsdl import-service.wsdl.xml import-service-wrapper.xsd microsoft-serialization.xsd import-service.disco.xml) do if not exist "%SRC%\%%F" (echo Missing %SRC%\%%F & exit /b 1)
if not exist "%DST%" mkdir "%DST%"
copy "%SRC%\*" "%DST%\" >nul
for %%F in ("%DST%\*") do certutil -hashfile "%%~fF" SHA256 >> "%DST%\manifest.sha256.txt"
echo Contract copied to private Gateway storage: %DST%.
