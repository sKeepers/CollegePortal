@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
echo Откат Gateway: автоматический откат пока ограничен остановкой службы и подсказкой по backup.
net stop CollegePortalGateway >nul 2>&1
echo Проверьте каталог C:\CollegePortalGateway\backup и восстановите нужный EXE/config вручную.
exit /b 0