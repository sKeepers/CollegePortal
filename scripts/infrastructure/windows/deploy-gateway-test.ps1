param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath,

    [Parameter(Mandatory = $true)]
    [string]$ExpectedSha256,

    [string]$HostAlias = "college-vipnet",
    [string]$PortalSshHost = "andale@192.168.34.104",
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

function Invoke-RemoteCapture {
    param([string]$Command)

    $output = & ssh -o BatchMode=yes $HostAlias $Command
    $exit = $LASTEXITCODE
    if ($exit -ne 0) {
        throw "Remote command failed with exit code ${exit}: $Command`n$output"
    }
    return $output
}

function Invoke-RemoteHealth {
    param([string]$Uri)

    $script = '$wc=New-Object Net.WebClient; $wc.DownloadString(''' + $Uri.Replace("'", "''") + ''')'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($script))
    Invoke-Remote "powershell.exe -NoProfile -NonInteractive -InputFormat None -ExecutionPolicy Bypass -EncodedCommand $encoded"
}

function Invoke-PortalHealth {
    param([string]$Uri)

    & ssh -o BatchMode=yes -o ConnectTimeout=8 $PortalSshHost "curl -sS -i --max-time 5 $Uri | head -n 20"
    if ($LASTEXITCODE -ne 0) { throw "Portal remote health check failed with exit code $LASTEXITCODE" }
}

if ($ExpectedSha256 -match "10\.0\.3\.1:8080") {
    throw "Production FIS endpoint :8080 is forbidden."
}

if (-not (Test-Path -LiteralPath $PackagePath)) {
    throw "Package not found: $PackagePath"
}

$PackagePath = [IO.Path]::GetFullPath($PackagePath)
$actualSha = Get-Sha256 $PackagePath
if ($actualSha -ne $ExpectedSha256.ToLowerInvariant()) {
    throw "Package SHA-256 mismatch. Expected $ExpectedSha256, got $actualSha"
}

$packageFile = [IO.Path]::GetFileName($PackagePath)
$packageName = [IO.Path]::GetFileNameWithoutExtension($PackagePath)
$remoteRoot = $RemoteDirectory.TrimEnd("\")
$remotePackageRoot = $remoteRoot + "\" + $packageName
$remoteZip = $remoteRoot + "\" + $packageFile

Write-Host "Package SHA-256 verified: $actualSha"
Write-Host "Host alias: $HostAlias"
Write-Host "Remote directory: $RemoteDirectory"
Write-Host "Remote package root: $remotePackageRoot"

if (-not $Copy -and -not $Install) {
    Write-Host "Dry-run only. Use -Copy to transfer package. Use -Install to transfer and run install-all.cmd after green CI."
    exit 0
}

if ($Install -and -not $Copy) {
    throw "-Install requires -Copy so the verified package is transferred in the same run."
}

$localStage = Join-Path ([IO.Path]::GetTempPath()) ("collegeportal-gateway-deploy-" + [guid]::NewGuid().ToString("N"))
try {
    New-Item -ItemType Directory -Force -Path $localStage | Out-Null
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $localStage -Force

    Invoke-Remote "if not exist `"$remoteRoot`" mkdir `"$remoteRoot`""
    Invoke-Remote "if exist `"$remotePackageRoot`" rmdir /s /q `"$remotePackageRoot`""
    Invoke-Remote "mkdir `"$remotePackageRoot`""

    & scp -q -o BatchMode=yes $PackagePath "$HostAlias`:$($remoteZip -replace '\\','/')"
    if ($LASTEXITCODE -ne 0) { throw "SCP package upload failed with exit code $LASTEXITCODE" }

    $remoteHashOutput = Invoke-RemoteCapture "certutil -hashfile `"$remoteZip`" SHA256"
    $remoteHashText = ($remoteHashOutput -join " ").ToLowerInvariant() -replace '[^0-9a-f]', ''
    if ($remoteHashText -notmatch [regex]::Escape($actualSha)) {
        throw "Remote package SHA-256 verification failed."
    }
    Write-Host "Remote package SHA-256 verified."

    $items = @(Get-ChildItem -LiteralPath $localStage -Force)
    foreach ($item in $items) {
        & scp -q -r -o BatchMode=yes $item.FullName "$HostAlias`:$($remotePackageRoot -replace '\\','/')/"
        if ($LASTEXITCODE -ne 0) { throw "SCP package content upload failed with exit code $LASTEXITCODE" }
    }
    Write-Host "Package content copied to ViPNet-PC."

    if (-not $Install) {
        Write-Host "Copy completed. Installation was not started."
        exit 0
    }

    Write-Host "Starting remote TEST installation. Production FIS :8080 and Import/Validate/Delete are not used."
    Invoke-Remote "cd /d `"$remotePackageRoot`" && cmd /d /v:on /c call install-all.cmd"

    Write-Host "Checking service status."
    Invoke-Remote "sc query CollegePortalGateway"
    Invoke-Remote "sc qc CollegePortalGateway"
    Invoke-Remote "sc qfailure CollegePortalGateway"
    Invoke-Remote "netstat -ano | findstr :8099"

    Write-Host "Checking local Gateway health from ViPNet-PC."
    Invoke-RemoteHealth "http://127.0.0.1:8099/health"
    Invoke-RemoteHealth "http://127.0.0.1:8099/version"
    Invoke-RemoteHealth "http://127.0.0.1:8099/adapters"

    Write-Host "Checking remote Gateway health from Portal host."
    Invoke-PortalHealth "http://192.168.34.223:8099/health"

    Write-Host "Gateway TEST deployment completed."
}
catch {
    Write-Host "[STOP-GATE] Remote deployment failed: $($_.Exception.Message)"
    Write-Host "Collect diagnostics with collect-gateway-diagnostics.ps1 before retrying."
    throw
}
finally {
    if ([IO.Directory]::Exists($localStage)) { Remove-Item -LiteralPath $localStage -Recurse -Force -ErrorAction SilentlyContinue }
}
