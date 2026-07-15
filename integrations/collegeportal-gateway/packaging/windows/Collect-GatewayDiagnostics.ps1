[CmdletBinding()]
param([string]$InstallRoot = 'C:\CollegePortalGateway')

$ErrorActionPreference = 'Continue'
Set-StrictMode -Version 2
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'Gateway-Common.ps1')
$Out = Join-Path $InstallRoot 'diagnostics\collegeportal-gateway-diagnostics.txt'
$Lines = New-Object Collections.Generic.List[string]

function Add-Section([string]$Name, [scriptblock]$Command) {
    $Lines.Add('')
    $Lines.Add(('===== {0} =====' -f $Name))
    try { $Lines.Add(((& $Command 2>&1 | Out-String).Trim())) } catch { $Lines.Add(('ERROR: ' + $_.Exception.Message)) }
}

$Lines.Add('CollegePortal Gateway diagnostics (без секретов и содержимого контрактов)')
$Lines.Add(('collected_at_utc={0}' -f [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')))
$Lines.Add(('computer={0}' -f $env:COMPUTERNAME))
$Lines.Add(('os={0}' -f [Environment]::OSVersion.VersionString))
try { $Lines.Add(('dotnet_release={0}' -f (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -Name Release).Release)) } catch { }

Add-Section 'SERVICE QUERY' { & sc.exe query CollegePortalGateway }
Add-Section 'SERVICE CONFIG' { & sc.exe qc CollegePortalGateway }
Add-Section 'SERVICE RECOVERY' { & sc.exe qfailure CollegePortalGateway }
Add-Section 'LISTENER 8099' { & netstat.exe -ano | Select-String ':8099' }
Add-Section 'URL ACL' { & netsh.exe http show urlacl url='http://+:8099/' }
Add-Section 'FIREWALL' { & netsh.exe advfirewall firewall show rule name='CollegePortal Gateway DEV 8099' verbose }
Add-Section 'ROUTE TO FIS TEST' { & route.exe print 10.0.3.1 }
Add-Section 'BINARY SHA256' { if ([IO.File]::Exists("$InstallRoot\bin\CollegePortal.Gateway.Host.exe")) { Get-CollegePortalSha256 "$InstallRoot\bin\CollegePortal.Gateway.Host.exe" } else { 'MISSING' } }
Add-Section 'REQUIRED FILES' {
    foreach ($Path in @(
        "$InstallRoot\bin\CollegePortal.Gateway.Host.exe",
        "$InstallRoot\config\gateway.private.config",
        "$InstallRoot\VERSION",
        "$InstallRoot\logs\startup.log"
    )) { '{0}={1}' -f $Path, [IO.File]::Exists($Path) }
}
Add-Section 'PRIVATE CONFIG ACL' { & icacls.exe "$InstallRoot\config\gateway.private.config" }
Add-Section 'STARTUP LOG (LAST 300 LINES)' {
    $StartupLog = Join-Path $InstallRoot 'logs\startup.log'
    if ([IO.File]::Exists($StartupLog)) { Get-GatewayFileTail $StartupLog 300 } else { 'MISSING' }
}
Add-Section 'LOCAL HEALTH' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ScriptRoot 'Test-GatewayHealth.ps1') -InstallRoot $InstallRoot }
Add-Section 'SYSTEM SERVICE ERRORS' { & wevtutil.exe qe System /q:"*[System[(Level=1 or Level=2) and Provider[@Name='Service Control Manager']]]" /c:10 /rd:true /f:text }
Add-Section 'APPLICATION ERRORS' { & wevtutil.exe qe Application /q:"*[System[(Level=1 or Level=2) and (Provider[@Name='.NET Runtime'] or Provider[@Name='Application Error'])]]" /c:10 /rd:true /f:text }

Write-GatewayUtf8 $Out $Lines.ToArray()
Write-Host "Диагностика сохранена: $Out"
Write-Host 'Перед передачей файла дополнительно проверьте отсутствие локальных имен пользователей и путей.'
