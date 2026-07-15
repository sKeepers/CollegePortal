$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
. (Join-Path $Root 'packaging\windows\Gateway-Common.ps1')

$Executable = Join-Path $Root 'artifacts\Release\CollegePortal.Gateway.Host.exe'
if (-not [IO.File]::Exists($Executable)) { throw 'Gateway executable is missing.' }
$Temp = Join-Path ([IO.Path]::GetTempPath()) ('collegeportal-gateway-smoke-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Temp) | Out-Null
$Secret = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('deterministic-gateway-smoke-secret-0000000001'))
$Config = Join-Path $Temp 'gateway.private.config'
$Port = Get-Random -Minimum 18100 -Maximum 18999
$Lines = @(
    "BindPrefix=http://127.0.0.1:$Port/",
    'AllowedPortalIps=127.0.0.1',
    "SharedSecret=$Secret",
    'FisTestEndpoint=http://10.0.3.1:8383/api/import/ImportService.svc',
    'EnableDangerousOperations=false',
    'FisProductionEnabled=false',
    'MaxBodyBytes=32',
    'RequestWindowSeconds=300',
    'RateLimitPerMinute=60',
    'ConnectTimeoutSeconds=1',
    'RequestTimeoutSeconds=2',
    "AuditLogPath=$Temp\audit.log",
    "NonceStorePath=$Temp\nonces.txt",
    "DiagnosticsPath=$Temp\diagnostics.json",
    'ServiceVersion=smoke-test'
)
[IO.File]::WriteAllLines($Config, $Lines, (New-Object Text.UTF8Encoding($false)))

$Process = $null
try {
    $StartInfo = New-Object Diagnostics.ProcessStartInfo
    $StartInfo.FileName = $Executable
    $StartInfo.Arguments = '--console --run-for-seconds 20 --config "' + $Config + '"'
    $StartInfo.UseShellExecute = $false
    $StartInfo.CreateNoWindow = $true
    $StartInfo.RedirectStandardOutput = $true
    $StartInfo.RedirectStandardError = $true
    $Process = New-Object Diagnostics.Process
    $Process.StartInfo = $StartInfo
    if (-not $Process.Start()) { throw 'Gateway console process did not start.' }
    $Ready = $false
    for ($Attempt = 0; $Attempt -lt 15; $Attempt++) {
        Start-Sleep -Milliseconds 250
        if ($Process.HasExited) {
            $ErrorText = $Process.StandardError.ReadToEnd()
            throw "Gateway console host exited before readiness: $ErrorText"
        }
        try {
            $Health = Invoke-GatewayHttp "http://127.0.0.1:$Port/health" 'GET' @{} (New-Object byte[] 0) 1
            if ($Health.StatusCode -eq 200) { $Ready = $true; break }
        } catch { }
    }
    if (-not $Ready) {
        if (-not $Process.HasExited) { try { $Process.Kill() } catch { } }
        $Process.WaitForExit(5000) | Out-Null
        $OutputText = $Process.StandardOutput.ReadToEnd()
        $ErrorText = $Process.StandardError.ReadToEnd()
        throw "Gateway console host did not become ready. stdout=$OutputText stderr=$ErrorText"
    }

    foreach ($Path in @('/health', '/version', '/adapters')) {
        $Result = Invoke-GatewayHttp ("http://127.0.0.1:$Port" + $Path) 'GET' @{} (New-Object byte[] 0) 2
        if ($Result.StatusCode -ne 200) { throw "$Path returned HTTP $($Result.StatusCode)." }
    }

    $ProtectedPath = '/diagnostics/latest'
    $Headers = Get-GatewayHmacHeaders 'GET' $ProtectedPath (New-Object byte[] 0) $Secret
    $First = Invoke-GatewayHttp ("http://127.0.0.1:$Port" + $ProtectedPath) 'GET' $Headers (New-Object byte[] 0) 2
    if ($First.StatusCode -ne 200) { throw 'Valid HMAC was rejected.' }
    $Replay = Invoke-GatewayHttp ("http://127.0.0.1:$Port" + $ProtectedPath) 'GET' $Headers (New-Object byte[] 0) 2
    if ($Replay.StatusCode -ne 403 -or $Replay.Content -notmatch 'reused_nonce') { throw 'Repeated nonce was not rejected.' }

    $Invalid = Get-GatewayHmacHeaders 'GET' $ProtectedPath (New-Object byte[] 0) $Secret
    $Invalid['X-Gateway-Signature'] = 'invalid'
    $InvalidResult = Invoke-GatewayHttp ("http://127.0.0.1:$Port" + $ProtectedPath) 'GET' $Invalid (New-Object byte[] 0) 2
    if ($InvalidResult.StatusCode -ne 403 -or $InvalidResult.Content -notmatch 'invalid_hmac') { throw 'Invalid HMAC was not rejected.' }

    $Expired = Get-GatewayHmacHeaders 'GET' $ProtectedPath (New-Object byte[] 0) $Secret
    $Expired['X-Gateway-Timestamp'] = [DateTime]::UtcNow.AddHours(-1).ToString('yyyy-MM-ddTHH:mm:ssZ')
    $ExpiredResult = Invoke-GatewayHttp ("http://127.0.0.1:$Port" + $ProtectedPath) 'GET' $Expired (New-Object byte[] 0) 2
    if ($ExpiredResult.StatusCode -ne 403 -or $ExpiredResult.Content -notmatch 'expired_timestamp') { throw 'Expired timestamp was not rejected.' }

    $OversizeBody = [Text.Encoding]::UTF8.GetBytes(('x' * 64))
    $Oversize = Invoke-GatewayHttp "http://127.0.0.1:$Port/diagnostics/run" 'POST' @{} $OversizeBody 2
    if ($Oversize.StatusCode -ne 413 -or $Oversize.Content -notmatch 'request_too_large') { throw 'Oversized body was not rejected before authentication.' }

    Write-Host '[OK] Console smoke: public endpoints, valid HMAC, invalid HMAC, expired timestamp, nonce replay and body limit.'
}
finally {
    if ($null -ne $Process -and -not $Process.HasExited) {
        try { $Process.Kill() } catch { }
        $Process.WaitForExit(5000) | Out-Null
    }
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
