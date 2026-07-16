[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath,
    [switch]$AllowSystemChanges
)

$ErrorActionPreference = 'Stop'
$ServiceName = 'CollegePortalGateway'
$InstallRoot = 'C:\CollegePortalGateway'
$FirewallRule = 'CollegePortal Gateway DEV 8099'
$UrlAcl = 'http://+:8099/'

if (-not $AllowSystemChanges) {
    throw 'Acceptance-test изменяет Windows service/URL ACL/firewall. Передайте -AllowSystemChanges только на disposable runner.'
}
$Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
if (-not $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Для acceptance-test установки службы требуются права администратора.'
}
if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    throw "Служба $ServiceName уже существует; acceptance-test отказывается изменять ее."
}
if ([IO.Directory]::Exists($InstallRoot)) {
    throw "Каталог $InstallRoot уже существует; acceptance-test отказывается его изменять."
}

function Invoke-Installer([string]$InstallerPath, [string]$WorkingDirectory) {
    $StartInfo = New-Object Diagnostics.ProcessStartInfo
    $StartInfo.FileName = 'cmd.exe'
    $StartInfo.Arguments = '/d /v:on /c call "' + $InstallerPath + '"'
    $StartInfo.WorkingDirectory = $WorkingDirectory
    $StartInfo.UseShellExecute = $false
    $StartInfo.CreateNoWindow = $true
    $StartInfo.RedirectStandardOutput = $true
    $StartInfo.RedirectStandardError = $true
    $StartInfo.StandardOutputEncoding = [Text.Encoding]::UTF8
    $StartInfo.StandardErrorEncoding = [Text.Encoding]::UTF8

    $Process = New-Object Diagnostics.Process
    $Process.StartInfo = $StartInfo
    [void]$Process.Start()
    $Stdout = $Process.StandardOutput.ReadToEnd()
    $Stderr = $Process.StandardError.ReadToEnd()
    $Process.WaitForExit()
    return New-Object PSObject -Property @{
        ExitCode = $Process.ExitCode
        Stdout = $Stdout
        Stderr = $Stderr
    }
}

function Assert-InstallerSuccess([object]$Result, [string]$Phase) {
    if ($Result.ExitCode -ne 0) {
        throw "$Phase install-all.cmd завершился с кодом $($Result.ExitCode).`nSTDOUT:`n$($Result.Stdout)`nSTDERR:`n$($Result.Stderr)"
    }
    foreach ($Marker in @('[OK] SHA256_VALIDATED', '[OK] SERVICE_INSTALLED')) {
        if (-not $Result.Stdout.Contains($Marker)) {
            throw "$Phase установщик не подтвердил этап $Marker.`nSTDOUT:`n$($Result.Stdout)`nSTDERR:`n$($Result.Stderr)"
        }
    }
}

function Get-CollegePortalTestSha256([string]$Path) {
    $Stream = [IO.File]::OpenRead($Path)
    $Sha = $null
    try {
        $Sha = [Security.Cryptography.SHA256]::Create()
        return ([BitConverter]::ToString($Sha.ComputeHash($Stream))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        if ($null -ne $Sha) { $Sha.Clear() }
        if ($null -ne $Stream) { $Stream.Close() }
    }
}

function Assert-PrivateConfigAcl([string]$Path) {
    $Acl = Get-Acl -LiteralPath $Path
    $Sids = New-Object Collections.Generic.List[string]
    foreach ($Rule in $Acl.Access) {
        try { $Sids.Add($Rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value) }
        catch { $Sids.Add([string]$Rule.IdentityReference) }
    }
    foreach ($Required in @('S-1-5-18', 'S-1-5-32-544', 'S-1-5-20')) {
        if ($Sids -notcontains $Required) { throw "Private config ACL does not include required SID $Required." }
    }
    foreach ($Forbidden in @('S-1-5-11', 'S-1-1-0')) {
        if ($Sids -contains $Forbidden) { throw "Private config ACL grants forbidden broad SID $Forbidden." }
    }
    if (-not $Acl.AreAccessRulesProtected) { throw 'Private config ACL inheritance must be disabled.' }
}

$PackagePath = [IO.Path]::GetFullPath($PackagePath)
if (-not [IO.File]::Exists($PackagePath)) { throw "Пакет не найден: $PackagePath" }
$Temp = Join-Path ([IO.Path]::GetTempPath()) ('CollegePortal Gateway Install ' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Temp) | Out-Null

try {
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $Temp
    $Installer = Join-Path $Temp 'install-all.cmd'
    if (-not [IO.File]::Exists($Installer)) { throw 'install-all.cmd отсутствует в пакете.' }

    $FirstInstall = Invoke-Installer $Installer $Temp
    Assert-InstallerSuccess $FirstInstall 'initial'

    $Service = Get-Service -Name $ServiceName -ErrorAction Stop
    if ($Service.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Running) {
        throw "Служба установлена, но имеет статус $($Service.Status)."
    }
    if (-not [IO.File]::Exists((Join-Path $InstallRoot 'bin\CollegePortal.Gateway.Host.exe'))) {
        throw 'Установленный Gateway EXE отсутствует.'
    }

    $PrivateConfig = Join-Path $InstallRoot 'config\gateway.private.config'
    if (-not [IO.File]::Exists($PrivateConfig)) { throw 'Private config отсутствует после установки.' }
    Assert-PrivateConfigAcl $PrivateConfig
    $PrivateConfigHash = Get-CollegePortalTestSha256 $PrivateConfig

    $RepairInstall = Invoke-Installer $Installer $Temp
    Assert-InstallerSuccess $RepairInstall 'repair'
    $RepairHash = Get-CollegePortalTestSha256 $PrivateConfig
    if ($RepairHash -ne $PrivateConfigHash) {
        throw 'Repair/update changed existing gateway.private.config.'
    }
    Assert-PrivateConfigAcl $PrivateConfig

    $Service = Get-Service -Name $ServiceName -ErrorAction Stop
    if ($Service.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Running) {
        throw "Служба после repair имеет статус $($Service.Status)."
    }
    Write-Host '[OK] install-all.cmd прошел SHA-256, установил службу, сохранил private config и повторно применил SID ACL при repair.'
}
finally {
    $Service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($null -ne $Service) {
        if ($Service.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Stopped) {
            Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
        }
        & sc.exe delete $ServiceName | Out-Null
    }
    & netsh.exe advfirewall firewall delete rule name=$FirewallRule | Out-Null
    & netsh.exe http delete urlacl url=$UrlAcl | Out-Null
    if ([IO.Directory]::Exists($InstallRoot)) { Remove-Item -LiteralPath $InstallRoot -Recurse -Force }
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
