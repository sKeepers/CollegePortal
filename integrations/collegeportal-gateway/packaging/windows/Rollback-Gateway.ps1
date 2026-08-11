[CmdletBinding()]
param([string]$InstallRoot = 'C:\CollegePortalGateway')

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
$Backups = @(Get-ChildItem -LiteralPath (Join-Path $InstallRoot 'backup') -ErrorAction SilentlyContinue | Where-Object { $_.PSIsContainer } | Sort-Object Name -Descending)
if ($Backups.Count -eq 0) { throw 'Резервная копия бинарных файлов не найдена.' }
$BackupBin = Join-Path $Backups[0].FullName 'bin'
if (-not (Test-Path -LiteralPath $BackupBin -PathType Container)) { throw "Некорректная резервная копия: $BackupBin" }
& sc.exe stop CollegePortalGateway | Out-Null
Start-Sleep -Seconds 2
Copy-Item -Path (Join-Path $BackupBin '*') -Destination (Join-Path $InstallRoot 'bin') -Force
& sc.exe start CollegePortalGateway | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Служба не запустилась после rollback.' }
Write-Host "[OK] Восстановлены бинарные файлы из $($Backups[0].Name). Private config не изменялся."
