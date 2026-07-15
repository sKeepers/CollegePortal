param([string]$Executable)

$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
if (-not $Executable) { $Executable = Join-Path $Root 'artifacts\Release\CollegePortal.Gateway.Host.exe' }
$Executable = [IO.Path]::GetFullPath($Executable)
if (-not [IO.File]::Exists($Executable)) { throw 'Gateway executable is missing.' }

$Temp = Join-Path ([IO.Path]::GetTempPath()) ('collegeportal-gateway-startup-' + [Guid]::NewGuid().ToString('N'))
$ConfigDirectory = Join-Path $Temp 'config'
[IO.Directory]::CreateDirectory($ConfigDirectory) | Out-Null
$Config = Join-Path $ConfigDirectory 'gateway.private.config'
$Secret = 'startup-diagnostics-secret-never-log-1234567890'

try {
    & $Executable --console --config $Config 2>$null
    if ($LASTEXITCODE -ne 2) { throw "Missing config exit code was $LASTEXITCODE instead of 2." }
    $StartupLog = Join-Path $Temp 'logs\startup.log'
    if (-not [IO.File]::Exists($StartupLog)) { throw 'startup.log was not created for missing config.' }
    $MissingLog = [IO.File]::ReadAllText($StartupLog)
    foreach ($Expected in @('error_code=CONFIG_NOT_FOUND', 'exception_type=CollegePortal.Gateway.GatewayStartupException', 'exception_hresult=', 'exception_to_string[0]=')) {
        if ($MissingLog -notmatch [Regex]::Escape($Expected)) { throw "Missing-config log is missing: $Expected" }
    }

    [IO.File]::WriteAllText((Join-Path $Temp 'VERSION'), 'diagnostic-test', (New-Object Text.UTF8Encoding($false)))
    $Lines = @(
        'BindPrefix=http://127.0.0.1:18099/',
        'AllowedPortalIps=127.0.0.1',
        "SharedSecret=$Secret",
        "InstallRoot=$Temp",
        'FisTestEndpoint=http://10.0.3.1:8383/api/import/ImportService.svc',
        'EnableDangerousOperations=invalid',
        'FisProductionEnabled=false'
    )
    [IO.File]::WriteAllLines($Config, $Lines, (New-Object Text.UTF8Encoding($false)))
    & $Executable --check-config --config $Config 2>$null
    if ($LASTEXITCODE -ne 2) { throw "Invalid config exit code was $LASTEXITCODE instead of 2." }
    $InvalidLog = [IO.File]::ReadAllText($StartupLog)
    if ($InvalidLog -notmatch 'error_code=CONFIG_INVALID') { throw 'CONFIG_INVALID was not logged.' }
    if ($InvalidLog.Contains($Secret)) { throw 'startup.log contains SharedSecret.' }
    if ($InvalidLog -notmatch 'exception_to_string\[0\]=System\.FormatException') { throw 'Full exception type/stack was not logged.' }

    $global:LASTEXITCODE = 0
    Write-Host '[OK] Startup diagnostics: missing and invalid config are classified, logged and redacted.'
}
finally {
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
