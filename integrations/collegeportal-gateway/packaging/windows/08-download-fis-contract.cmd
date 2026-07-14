@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set OUT=%ROOT%\specs\fis\discovered
set BASE=http://10.0.3.1:8383/api/import/ImportService.svc
if not exist "%OUT%" mkdir "%OUT%"

echo Скачивание WSDL/XSD ФИС TEST в %OUT%
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; function sha($p){ $s=[IO.File]::OpenRead($p); try { $sha=[Security.Cryptography.SHA256]::Create(); try { (($sha.ComputeHash($s) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() } } finally { $s.Dispose() } }; $out='%OUT%'; $base='%BASE%'; $items=@(@('singleWsdl.xml','?singleWsdl'),@('service.wsdl','?wsdl'),@('schema-xsd0.xsd','?xsd=xsd0'),@('schema-xsd1.xsd','?xsd=xsd1'),@('service.disco','?disco')); $wc=New-Object Net.WebClient; foreach($i in $items){ $file=Join-Path $out $i[0]; $url=$base+$i[1]; Write-Host ('Скачивание '+$url); $text=$wc.DownloadString($url); if([string]::IsNullOrWhiteSpace($text) -or $text.TrimStart()[0] -ne '<'){ throw 'Ответ не похож на XML: '+$url }; [IO.File]::WriteAllText($file,$text,[Text.Encoding]::UTF8); $hash=sha $file; Write-Host ('OK '+$i[0]+' SHA256='+$hash) }; Get-ChildItem -LiteralPath $out | Where-Object { -not $_.PSIsContainer } | Sort-Object Name | ForEach-Object { (sha $_.FullName)+'  '+$_.Name } | Set-Content -Encoding ASCII (Join-Path $out 'SHA256SUMS')"
if errorlevel 1 (
  echo ВНИМАНИЕ: автоматическая загрузка через PowerShell не выполнена. Пробую резервный способ certutil.
  certutil -urlcache -split -f "%BASE%?singleWsdl" "%OUT%\singleWsdl.xml"
  certutil -urlcache -split -f "%BASE%?wsdl" "%OUT%\service.wsdl"
  certutil -urlcache -split -f "%BASE%?xsd=xsd0" "%OUT%\schema-xsd0.xsd"
  certutil -urlcache -split -f "%BASE%?xsd=xsd1" "%OUT%\schema-xsd1.xsd"
  certutil -urlcache -split -f "%BASE%?disco" "%OUT%\service.disco"
  if errorlevel 1 (
    echo ОШИБКА: резервная загрузка certutil не выполнена.
    echo Резервный ручной способ: откройте URL в браузере на ViPNet-ПК и сохраните файлы в %OUT%.
    exit /b 1
  )
  for %%F in ("%OUT%\singleWsdl.xml" "%OUT%\service.wsdl" "%OUT%\schema-xsd0.xsd" "%OUT%\schema-xsd1.xsd" "%OUT%\service.disco") do (
    findstr /B /C:"<" %%F >nul 2>&1
    if errorlevel 1 (
      echo ОШИБКА: файл не похож на XML: %%F
      exit /b 1
    )
    certutil -hashfile %%F SHA256
  )
)
echo OK: WSDL/XSD скачаны, XML и SHA256 проверены.
exit /b 0