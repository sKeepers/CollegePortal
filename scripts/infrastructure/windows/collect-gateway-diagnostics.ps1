param(
    [string]$HostAlias = "college-vipnet",
    [string]$OutputDirectory = ".\tmp\gateway-diagnostics"
)

$ErrorActionPreference = "Stop"

function Invoke-RemoteText {
    param(
        [string]$Label,
        [string]$RemoteCommand
    )

    "== $Label =="
    & ssh -o BatchMode=yes $HostAlias $RemoteCommand
    "exit_code=$LASTEXITCODE"
    ""
}

function Assert-SafeOutput {
    param([string]$Text)

    $blocked = @(
        "gateway.private.config",
        "HMAC",
        "FIS_PASSWORD",
        "FIS_USERNAME",
        "Authorization:",
        "10.0.3.1:8080"
    )

    foreach ($pattern in $blocked) {
        if ($Text -match [regex]::Escape($pattern)) {
            throw "Diagnostics output contains blocked sensitive marker: $pattern"
        }
    }
}

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$outFile = Join-Path $OutputDirectory "vipnet-gateway-diagnostics-$stamp.txt"

$sections = New-Object System.Collections.Generic.List[string]
$sections.Add("CollegePortal Gateway read-only diagnostics")
$sections.Add("generated_at=$((Get-Date).ToString('s'))")
$sections.Add("host_alias=$HostAlias")
$sections.Add("")

$commands = @(
    @("hostname", "hostname"),
    @("whoami", "whoami"),
    @("sshd", "sc query sshd"),
    @("gateway_service", "sc query CollegePortalGateway"),
    @("gateway_service_config", "sc qc CollegePortalGateway"),
    @("port_8099", "netstat -ano | findstr :8099"),
    @("install_dir", "dir C:\CollegePortalGateway"),
    @("diagnostics_dir", "dir C:\CollegePortalGateway\diagnostics"),
    @("installation_report", "type C:\CollegePortalGateway\diagnostics\installation-report.txt")
)

foreach ($entry in $commands) {
    $label = $entry[0]
    $command = $entry[1]
    $text = (Invoke-RemoteText $label $command) -join [Environment]::NewLine
    Assert-SafeOutput $text
    $sections.Add($text)
}

[IO.File]::WriteAllText($outFile, ($sections -join [Environment]::NewLine), (New-Object System.Text.UTF8Encoding($false)))
Write-Host "Diagnostics saved: $outFile"
