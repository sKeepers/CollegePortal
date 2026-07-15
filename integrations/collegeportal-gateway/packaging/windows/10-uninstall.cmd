@echo off
chcp 65001 >nul
set /p CONFIRM=Для удаления службы введите УДАЛИТЬ:
if /I not "%CONFIRM%"=="УДАЛИТЬ" (
  echo Отменено.
  exit /b 1
)
net stop CollegePortalGateway >nul 2>&1
sc.exe delete CollegePortalGateway || exit /b 1
netsh.exe advfirewall firewall delete rule name="CollegePortal Gateway DEV 8099" >nul
netsh.exe http delete urlacl url=http://+:8099/ >nul
echo Служба удалена. Каталог C:\CollegePortalGateway и private config сохранены.
