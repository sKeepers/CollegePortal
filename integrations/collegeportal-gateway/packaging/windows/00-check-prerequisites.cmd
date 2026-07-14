@echo off
chcp 866 >nul
setlocal

echo [1/5] Проверка прав администратора и .NET Framework 4.8...
net session >nul 2>&1 || (
  echo ОШИБКА: запустите cmd.exe от имени администратора.
  exit /b 1
)

reg query "HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full" /v Release >nul 2>&1 || (
  echo ОШИБКА: .NET Framework 4.8 не найден.
  exit /b 1
)

where sc >nul 2>&1 || (
  echo ОШИБКА: утилита sc.exe не найдена.
  exit /b 1
)

echo OK: prerequisites пройдены.
exit /b 0
