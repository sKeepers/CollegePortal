@echo off
chcp 65001 >nul
setlocal EnableExtensions DisableDelayedExpansion
title Установка CollegePortal Gateway
echo ================================================================
echo  CollegePortal Gateway - установка/восстановление Windows-службы
echo  TEST only. ФИС production :8080 не используется.
echo ================================================================
call "%~dp001-install.cmd" %*
if errorlevel 1 (
  echo [STOP-GATE] Установка не завершена. SOAP и скачивание контрактов запрещены.
  exit /b 1
)
echo [OK] Установка завершена. Следующий шаг выполняется отдельно через gateway-menu.cmd.
exit /b 0
