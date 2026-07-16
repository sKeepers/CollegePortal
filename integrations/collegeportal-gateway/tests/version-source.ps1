[CmdletBinding()]
param(
    [string]$Executable
)

$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
$Version = ([IO.File]::ReadAllText((Join-Path $Root 'VERSION'))).Trim()
$Generated = Join-Path $Root 'src\CollegePortal.Gateway.Core\GatewayBuildVersion.g.cs'
if (-not [IO.File]::Exists($Generated)) { throw 'GatewayBuildVersion.g.cs was not generated.' }
$GeneratedText = [IO.File]::ReadAllText($Generated)
if ($GeneratedText -notmatch [Regex]::Escape('public const string Value = "' + $Version + '";')) {
    throw 'Generated GatewayBuildVersion does not match VERSION.'
}

$ConfigExample = [IO.File]::ReadAllText((Join-Path $Root 'config.example'))
if ($ConfigExample -match '(?im)^ServiceVersion\s*=') {
    throw 'config.example must not define ServiceVersion.'
}
$ConfigSource = [IO.File]::ReadAllText((Join-Path $Root 'src\CollegePortal.Gateway.Core\GatewayConfig.cs'))
if ($ConfigSource -match '0\.2\.5-dev' -or $ConfigSource -match 'Get\(values,\s*"ServiceVersion"') {
    throw 'GatewayConfig still contains stale or configurable ServiceVersion.'
}

if (-not $Executable) { $Executable = Join-Path $Root 'artifacts\Release\CollegePortal.Gateway.Host.exe' }
$Executable = [IO.Path]::GetFullPath($Executable)
if (-not [IO.File]::Exists($Executable)) { throw 'Gateway executable is missing.' }
$FileVersion = [Diagnostics.FileVersionInfo]::GetVersionInfo($Executable)
if ($FileVersion.ProductVersion -ne $Version) {
    throw "Executable product version is '$($FileVersion.ProductVersion)', expected '$Version'."
}

$Temp = Join-Path ([IO.Path]::GetTempPath()) ('collegeportal-gateway-version-source-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory((Join-Path $Temp 'src\CollegePortal.Gateway.Core')) | Out-Null
try {
    [IO.File]::WriteAllText((Join-Path $Temp 'VERSION'), 'invalid version', (New-Object Text.UTF8Encoding($false)))
    $Failed = $false
    try { & (Join-Path $Root 'scripts\Generate-GatewayVersionSource.ps1') -Root $Temp 2>$null } catch { $Failed = $true }
    if (-not $Failed) { throw 'Invalid VERSION was accepted.' }
} finally {
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}

Write-Host "[OK] Gateway version source is canonical: $Version"
