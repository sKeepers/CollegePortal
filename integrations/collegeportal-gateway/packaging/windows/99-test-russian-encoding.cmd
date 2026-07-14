@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
if not exist "%ROOT%\diagnostics" mkdir "%ROOT%\diagnostics"
set OUT=%ROOT%\diagnostics\russian-encoding-test.log
(
  echo Проверка русской кодировки Windows-1251
  echo Дата: %DATE% %TIME%
  echo Тестовая строка: Привет, колледж. Проверка: Ё ё Ж ж Я я.
  echo Ожидаемая кодовая страница CMD: 1251
) > "%OUT%"
echo Тест русской кодировки записан в %OUT%
exit /b 0