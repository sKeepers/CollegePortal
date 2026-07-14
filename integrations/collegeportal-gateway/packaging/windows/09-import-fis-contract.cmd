@echo off
chcp 1251 >nul
setlocal EnableExtensions EnableDelayedExpansion
set ROOT=C:\CollegePortalGateway
set IN=%ROOT%\specs\fis\discovered
set MANIFEST=%IN%\manifest.txt
if not exist "%IN%" mkdir "%IN%"

echo Импорт и анализ локального контракта ФИС
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; function sha($p){ $s=[IO.File]::OpenRead($p); try { $sha=[Security.Cryptography.SHA256]::Create(); try { (($sha.ComputeHash($s) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() } } finally { $s.Dispose() } }; $dir='%IN%'; $files=Get-ChildItem -LiteralPath $dir | Where-Object { -not $_.PSIsContainer -and $_.Name -match '\.(xml|wsdl|xsd|disco)$' }; if($files.Count -eq 0){ throw 'Файлы WSDL/XSD не найдены в '+$dir }; $ops=@(); $namespaces=@(); foreach($f in $files){ try { [xml]$xml=Get-Content -LiteralPath $f.FullName -Raw; $namespaces += $xml.DocumentElement.NamespaceURI; $ops += Select-Xml -Xml $xml -XPath '//*[local-name()="operation"]/@name' | ForEach-Object { $_.Node.Value } } catch { throw 'Ошибка XML в '+$f.FullName+': '+$_.Exception.Message } }; $ops=$ops | Where-Object { $_ } | Sort-Object -Unique; $namespaces=$namespaces | Where-Object { $_ } | Sort-Object -Unique; $lines=@(); $lines+='Манифест контракта ФИС'; $lines+='Дата: '+(Get-Date -Format 'yyyy-MM-dd HH:mm:ss'); $lines+='Service: ImportService'; $lines+='Interface: IImportService'; $lines+='Namespaces:'; $lines += $namespaces | ForEach-Object { '  '+$_ }; $lines+='Operations:'; $lines += $ops | ForEach-Object { '  '+$_ }; $lines+='SHA256:'; $lines += $files | Sort-Object Name | ForEach-Object { (sha $_.FullName)+'  '+$_.Name }; [IO.File]::WriteAllLines('%MANIFEST%', $lines, [Text.Encoding]::UTF8); Write-Host ('OK: manifest создан '+ '%MANIFEST%')"
if errorlevel 1 (
  echo ОШИБКА: импорт контракта ФИС не выполнен.
  echo Каталог: %IN%
  echo Manifest: %MANIFEST%
  exit /b 1
)
echo OK: контракт ФИС проанализирован.
exit /b 0