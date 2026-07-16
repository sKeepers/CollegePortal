param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath,

    [Parameter(Mandatory = $true)]
    [string]$ExpectedSha256,

    [string]$HostAlias = "college-vipnet",
    [string]$RemoteDirectory = "C:\Windows\Temp\CollegePortalGatewayDeploy",
    [switch]$Copy,
    [switch]$Install
)

$ErrorActionPreference = "Stop"

function Get-Sha256 {
    param([string]$Path)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    $stream = [IO.File]::OpenRead($Path)
    try {
        $hash = $sha.ComputeHash($stream)
        return ([BitConverter]::ToString($hash) -replace "-", "").ToLowerInvariant()
    }
    finally {
        $stream.Close()
        $sha.Clear()
    }
}

function Invoke-Remote {
    param([string]$Command)

    & ssh -o BatchMode=yes $HostAlias $Command
    if ($LASTEXITCODE -ne 0) {
        throw "Remote command failed with exit code $LASTEXITCODE"
    }
}

if ($ExpectedSha256 -match "10\.0\.3\.1:8080") {
    throw "Production FIS endpoint :8080 is forbidden."
}

if (-not (Test-Path -LiteralPath $PackagePath)) {
    throw "Package not found: $PackagePath"
}

$actualSha = Get-Sha256 $PackagePath
if ($actualSha -ne $ExpectedSha256.ToLowerInvariant()) {
    throw "Package SHA-256 mismatch. Expected $ExpectedSha256, got $actualSha"
}

Write-Host "Package SHA-256 verified: $actualSha"
Write-Host "Host alias: $HostAlias"
Write-Host "Remote directory: $RemoteDirectory"

if (-not $Copy -and -not $Install) {
    Write-Host "Dry-run only. Use -Copy to transfer package. Use -Install only after separate operator confirmation."
    exit 0
}

if ($Install -and -not $Copy) {
    throw "-Install requires -Copy so the verified package is transferred in the same run."
}

$remoteZip = ($RemoteDirectory.TrimEnd("\") + "\" + [IO.Path]::GetFileName($PackagePath))
Invoke-Remote "if not exist `"$RemoteDirectory`" mkdir `"$RemoteDirectory`""
& scp -q -o BatchMode=yes $PackagePath "$HostAlias`:$($remoteZip -replace '\\','/')"
if ($LASTEXITCODE -ne 0) {
    throw "SCP upload failed with exit code $LASTEXITCODE"
}

Write-Host "Package copied to ViPNet-PC."
Invoke-Remote "certutil -hashfile `"$remoteZip`" SHA256"

if (-not $Install) {
    Write-Host "Copy completed. Installation was not started."
    exit 0
}

Write-Host "STOP-GATE: this script does not perform automatic installation yet."
Write-Host "Run install-all.cmd manually or extend this script only after service-installation smoke stays green."
exit 2
