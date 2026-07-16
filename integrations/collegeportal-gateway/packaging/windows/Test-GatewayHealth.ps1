[CmdletBinding()]
param([string]$InstallRoot = 'C:\CollegePortalGateway')

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'Gateway-Common.ps1')

$BaseUri = 'http://127.0.0.1:8099'
$CoreFailed = $false
foreach ($Path in @('/health', '/version', '/adapters')) {
    try {
        $Result = Invoke-GatewayHttp ($BaseUri + $Path) 'GET' @{} (New-Object byte[] 0) 10
        Write-Host ("{0}: HTTP {1}, {2} ms" -f $Path, $Result.StatusCode, $Result.DurationMs)
        if ($Result.StatusCode -ne 200) { $CoreFailed = $true }
    }
    catch {
        Write-Host ("{0}: НЕДОСТУПЕН ({1})" -f $Path, $_.Exception.Message)
        $CoreFailed = $true
    }
}

if ($CoreFailed) { Write-Host '[STOP-GATE] Локальные Gateway endpoints не готовы.'; exit 1 }

$ConfigPath = Join-Path $InstallRoot 'config\gateway.private.config'
$Config = Read-GatewayConfig $ConfigPath
$Path = '/adapters/fis/health'
$Headers = Get-GatewayHmacHeaders 'GET' $Path (New-Object byte[] 0) $Config['SharedSecret']
try {
    $Fis = Invoke-GatewayHttp ($BaseUri + $Path) 'GET' $Headers (New-Object byte[] 0) 15
    Write-Host ("{0}: HTTP {1}, {2} ms" -f $Path, $Fis.StatusCode, $Fis.DurationMs)
    if ($Fis.StatusCode -eq 403) { Write-Host '[STOP-GATE] Gateway отклонил подписанную локальную проверку.'; exit 2 }
    if ($Fis.StatusCode -ne 200) { Write-Host '[ПРЕДУПРЕЖДЕНИЕ] Gateway работает, но ФИС TEST пока недоступен. SOAP запрещен.' }
}
catch {
    Write-Host ('[ПРЕДУПРЕЖДЕНИЕ] FIS adapter health не завершен: ' + $_.Exception.Message)
}

Write-Host '[OK] Локальный CollegePortal Gateway отвечает; production отключен.'
