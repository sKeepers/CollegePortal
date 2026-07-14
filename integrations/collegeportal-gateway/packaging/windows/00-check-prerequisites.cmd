@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion

echo [1/12] Проверка прав администратора, Windows 7 и .NET Framework 4.8
net session >nul 2>&1
if errorlevel 1 (
  echo ОШИБКА: установщик должен быть запущен из cmd.exe от имени администратора.
  echo Команда: net session
  echo Код ошибки: !ERRORLEVEL!
  exit /b 1
)

ver | findstr /I "6.1" >nul
if errorlevel 1 (
  echo ВНИМАНИЕ: текущая версия Windows отличается от Windows 7 SP1.
  ver
) else (
  echo OK: обнаружена Windows 7 / Windows Server 2008 R2 family.
)

reg query "HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full" /v Release >nul 2>&1
if errorlevel 1 (
  echo ОШИБКА: .NET Framework 4.8 не найден.
  echo Команда: reg query HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full /v Release
  echo Установите .NET Framework 4.8 и повторите запуск.
  exit /b 1
)

for /f "tokens=3" %%R in ('reg query "HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full" /v Release 2^>nul ^| findstr /I "Release"') do set NET_RELEASE=%%R
if not defined NET_RELEASE (
  echo ОШИБКА: не удалось определить Release .NET Framework.
  exit /b 1
)
if !NET_RELEASE! LSS 528040 (
  echo ОШИБКА: установленная версия .NET Framework ниже 4.8. Release=!NET_RELEASE!
  exit /b 1
)

where sc.exe >nul 2>&1
if errorlevel 1 (
  echo ОШИБКА: системная утилита sc.exe не найдена.
  exit /b 1
)
where netsh.exe >nul 2>&1
if errorlevel 1 (
  echo ОШИБКА: системная утилита netsh.exe не найдена.
  exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "exit 0" >nul 2>&1
if errorlevel 1 (
  echo ВНИМАНИЕ: PowerShell недоступен. WSDL/XSD discovery будет ограничен резервным режимом.
) else (
  echo OK: PowerShell доступен.
)

echo OK: предварительные проверки пройдены.
exit /b 0