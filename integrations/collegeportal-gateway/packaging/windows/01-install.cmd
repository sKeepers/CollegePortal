@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set SERVICE=CollegePortalGateway
set ALLOWED_IP=192.168.34.104
set PKG_ROOT=%~dp0..\..
for %%I in ("%PKG_ROOT%") do set PKG_ROOT=%%~fI
set EXE_NAME=CollegePortal.Gateway.Host.exe
set EXE_SRC=%PKG_ROOT%\bin\%EXE_NAME%
set EXE_DST=%ROOT%\bin\%EXE_NAME%
set CFG_SRC=%PKG_ROOT%\config.example
set CFG_DST=%ROOT%\config\gateway.private.config
set BINPATH="%EXE_DST%" --config "%CFG_DST%"

echo [2/12] Проверка файлов пакета
if not exist "%EXE_SRC%" (
  echo ОШИБКА: рабочий EXE отсутствует в пакете.
  echo Файл: %EXE_SRC%
  echo Действие: пересоберите пакет на рабочей станции.
  exit /b 1
)
if not exist "%CFG_SRC%" (
  echo ОШИБКА: config.example отсутствует в пакете.
  echo Файл: %CFG_SRC%
  exit /b 1
)
if not exist "%PKG_ROOT%\SHA256SUMS" (
  echo ОШИБКА: SHA256SUMS отсутствует в пакете.
  echo Файл: %PKG_ROOT%\SHA256SUMS
  exit /b 1
)

echo [3/12] Проверка SHA256 файлов пакета
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; function sha($p){ $s=[IO.File]::OpenRead($p); try { $sha=[Security.Cryptography.SHA256]::Create(); try { (($sha.ComputeHash($s) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() } } finally { $s.Dispose() } }; $root='%PKG_ROOT%'; $bad=@(); Get-Content -LiteralPath (Join-Path $root 'SHA256SUMS') | ForEach-Object { if ($_ -match '^([0-9a-fA-F]{64})\s+(.+)$') { $expected=$matches[1].ToLower(); $rel=$matches[2].Trim(); $path=Join-Path $root ($rel -replace '/', '\'); if (!(Test-Path -LiteralPath $path)) { $bad += 'не найден: '+$rel } else { $actual=sha $path; if ($actual -ne $expected) { $bad += 'sha256 не совпал: '+$rel } } } }; if ($bad.Count -gt 0) { $bad | ForEach-Object { Write-Host $_ }; exit 1 }"
if errorlevel 1 (
  echo ОШИБКА: проверка SHA256 не пройдена.
  echo Команда: проверка SHA256SUMS через PowerShell и .NET SHA256
  exit /b 1
)

echo [4/12] Создание каталогов %ROOT%
for %%D in ("%ROOT%" "%ROOT%\bin" "%ROOT%\config" "%ROOT%\logs" "%ROOT%\backup" "%ROOT%\cache" "%ROOT%\diagnostics" "%ROOT%\specs" "%ROOT%\specs\fis" "%ROOT%\specs\fis\discovered" "%ROOT%\updates") do (
  if not exist %%D mkdir %%D
  if errorlevel 1 (
    echo ОШИБКА: не удалось создать каталог %%D
    echo Код ошибки: !ERRORLEVEL!
    exit /b 1
  )
)

echo [5/12] Копирование файлов Gateway
copy /Y "%EXE_SRC%" "%EXE_DST%" >nul
if errorlevel 1 (
  echo ОШИБКА: не удалось скопировать EXE.
  echo Источник: %EXE_SRC%
  echo Назначение: %EXE_DST%
  echo Код ошибки: !ERRORLEVEL!
  exit /b 1
)
if exist "%CFG_DST%" (
  echo OK: существующий private config сохранен: %CFG_DST%
) else (
  copy "%CFG_SRC%" "%CFG_DST%" >nul
  if errorlevel 1 (
    echo ОШИБКА: не удалось создать private config.
    echo Источник: %CFG_SRC%
    echo Назначение: %CFG_DST%
    exit /b 1
  )
  echo OK: создан private config из config.example: %CFG_DST%
)
findstr /I "SharedSecret=CHANGE_ME" "%CFG_DST%" >nul 2>&1
if not errorlevel 1 (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $path='%CFG_DST%'; $bytes=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes); $secret=[Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','-').Replace('/','_'); $text=[IO.File]::ReadAllText($path); $text=[Regex]::Replace($text,'(?m)^SharedSecret=.*$','SharedSecret='+$secret); [IO.File]::WriteAllText($path,$text,[Text.Encoding]::ASCII)"
  if errorlevel 1 (
    echo ОШИБКА: не удалось автоматически создать SharedSecret.
    echo Файл: %CFG_DST%
    exit /b 1
  )
  echo OK: SharedSecret автоматически создан и сохранен в private config. Значение не выводится.
)
copy /Y "%PKG_ROOT%\VERSION" "%ROOT%\VERSION" >nul 2>&1
copy /Y "%PKG_ROOT%\SHA256SUMS" "%ROOT%\SHA256SUMS" >nul 2>&1

echo [6/12] Настройка Firewall для сервера %ALLOWED_IP%
netsh advfirewall firewall delete rule name="CollegePortal Gateway" >nul 2>&1
netsh advfirewall firewall add rule name="CollegePortal Gateway" dir=in action=allow protocol=TCP localport=8099 remoteip=%ALLOWED_IP% program="%EXE_DST%" enable=yes profile=any >nul 2>&1
if errorlevel 1 (
  echo ВНИМАНИЕ: не удалось настроить firewall через netsh advfirewall.
  echo Команда: netsh advfirewall firewall add rule name="CollegePortal Gateway" ...
  echo Проверьте firewall вручную. Установка продолжится.
) else (
  echo OK: firewall разрешает TCP 8099 только для %ALLOWED_IP%.
)

echo [7/12] Регистрация или обновление службы %SERVICE%
sc query "%SERVICE%" >nul 2>&1
if errorlevel 1 (
  sc create "%SERVICE%" binPath= "%BINPATH%" start= demand DisplayName= "CollegePortal Gateway"
  if errorlevel 1 (
    echo ОШИБКА: не удалось зарегистрировать службу.
    echo Команда: sc create %SERVICE% binPath= %BINPATH%
    echo Код ошибки: !ERRORLEVEL!
    exit /b 1
  )
) else (
  echo OK: служба уже существует. Обновляю путь запуска.
  sc config "%SERVICE%" binPath= "%BINPATH%" start= demand DisplayName= "CollegePortal Gateway"
  if errorlevel 1 (
    echo ОШИБКА: не удалось обновить путь службы.
    echo Команда: sc config %SERVICE% binPath= %BINPATH%
    echo Код ошибки: !ERRORLEVEL!
    exit /b 1
  )
)
sc description "%SERVICE%" "CollegePortal Gateway для защищенных интеграций" >nul 2>&1
sc qc "%SERVICE%" | findstr /I "%EXE_NAME%" >nul
if errorlevel 1 (
  echo ОШИБКА: служба зарегистрирована с неверным путем.
  echo Ожидаемый EXE: %EXE_DST%
  echo Текущая конфигурация:
  sc qc "%SERVICE%"
  exit /b 1
)

echo OK: Gateway установлен. Причина прошлой ошибки System Error 2 устранена обновлением binPath службы.
exit /b 0