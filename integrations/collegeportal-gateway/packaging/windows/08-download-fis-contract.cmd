@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set OUT=%ROOT%\specs\fis\discovered
set URL=http://10.0.3.1:8383/api/import/ImportService.svc
if not exist "%OUT%" mkdir "%OUT%"
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing %URL%?singleWsdl -OutFile %OUT%\import-service.single.wsdl; Invoke-WebRequest -UseBasicParsing %URL%?wsdl -OutFile %OUT%\import-service.wsdl.xml; Invoke-WebRequest -UseBasicParsing %URL%?xsd=xsd0 -OutFile %OUT%\import-service-wrapper.xsd; Invoke-WebRequest -UseBasicParsing %URL%?xsd=xsd1 -OutFile %OUT%\microsoft-serialization.xsd; Invoke-WebRequest -UseBasicParsing %URL%?disco -OutFile %OUT%\import-service.disco.xml"
for %%F in ("%OUT%\*") do certutil -hashfile "%%~fF" SHA256
echo TEST contract downloaded to %OUT%.
