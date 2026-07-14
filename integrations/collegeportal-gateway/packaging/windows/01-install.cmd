@echo off
chcp 866 >nul
setlocal
set ROOT=C:\CollegePortalGateway
set SERVICE=CollegePortalGateway
set PKG_ROOT=%~dp0..\..
set EXE_NAME=CollegePortal.Gateway.Host.exe
set EXE_SRC=%PKG_ROOT%\bin\%EXE_NAME%
set EXE_DST=%ROOT%\bin\%EXE_NAME%
set CFG_SRC=%PKG_ROOT%\config.example
set CFG_DST=%ROOT%\config\gateway.private.config
set BINPATH="%EXE_DST%" --config "%CFG_DST%"

echo [2/5] Установка файлов и службы CollegePortal Gateway...
call "%~dp000-check-prerequisites.cmd" || exit /b 1

if not exist "%EXE_SRC%" (
  echo ОШИБКА: в пакете отсутствует %EXE_SRC%
  echo Сначала соберите gateway и проверьте ZIP.
  exit /b 1
)

if not exist "%CFG_SRC%" (
  echo ОШИБКА: в пакете отсутствует %CFG_SRC%
  exit /b 1
)

for %%D in ("%ROOT%" "%ROOT%\bin" "%ROOT%\config" "%ROOT%\logs" "%ROOT%\cache" "%ROOT%\updates" "%ROOT%\backup" "%ROOT%\diagnostics" "%ROOT%\specs") do (
  if not exist %%D mkdir %%D
)

if exist "%CFG_DST%" (
  echo Существующий gateway.private.config сохранен.
) else (
  copy "%CFG_SRC%" "%CFG_DST%" >nul || (
    echo ОШИБКА: не удалось создать %CFG_DST%
    exit /b 1
  )
  echo Создан шаблон конфигурации: %CFG_DST%
)

copy "%EXE_SRC%" "%EXE_DST%" >nul || (
  echo ОШИБКА: не удалось скопировать %EXE_NAME% в %ROOT%\bin
  exit /b 1
)

if not exist "%EXE_DST%" (
  echo ОШИБКА: после копирования executable не найден: %EXE_DST%
  exit /b 1
)

sc query "%SERVICE%" >nul 2>&1
if errorlevel 1 (
  sc create "%SERVICE%" binPath= "%BINPATH%" start= demand || (
    echo ОШИБКА: не удалось зарегистрировать службу %SERVICE%.
    exit /b 1
  )
) else (
  echo Служба уже существует. Обновляю путь запуска.
  sc config "%SERVICE%" binPath= "%BINPATH%" start= demand || (
    echo ОШИБКА: не удалось обновить путь службы %SERVICE%.
    exit /b 1
  )
)

sc description "%SERVICE%" "CollegePortal Gateway for protected integrations" >nul 2>&1
sc qc "%SERVICE%" | findstr /I "%EXE_NAME%" >nul || (
  echo ОШИБКА: служба зарегистрирована с неверным путем. Текущая конфигурация:
  sc qc "%SERVICE%"
  exit /b 1
)

echo OK: служба %SERVICE% установлена и указывает на %EXE_DST%
exit /b 0
