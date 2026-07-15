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

$PackagePath = [IO.Path]::GetFullPath($PackagePath)
if (-not [IO.File]::Exists($PackagePath)) { throw "Пакет не найден: $PackagePath" }
$Temp = Join-Path ([IO.Path]::GetTempPath()) ('CollegePortal Gateway Install ' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Temp) | Out-Null

try {
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $Temp
    $Installer = Join-Path $Temp 'install-all.cmd'
    if (-not [IO.File]::Exists($Installer)) { throw 'install-all.cmd отсутствует в пакете.' }

    $StartInfo = New-Object Diagnostics.ProcessStartInfo
    $StartInfo.FileName = 'cmd.exe'
    $StartInfo.Arguments = '/d /v:on /c call "' + $Installer + '"'
    $StartInfo.WorkingDirectory = $Temp
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
    if ($Process.ExitCode -ne 0) {
        throw "install-all.cmd завершился с кодом $($Process.ExitCode).`nSTDOUT:`n$Stdout`nSTDERR:`n$Stderr"
    }
    foreach ($Marker in @('[OK] SHA256_VALIDATED', '[OK] SERVICE_INSTALLED')) {
        if (-not $Stdout.Contains($Marker)) {
            throw "Установщик не подтвердил этап $Marker.`nSTDOUT:`n$Stdout`nSTDERR:`n$Stderr"
        }
    }

    $Service = Get-Service -Name $ServiceName -ErrorAction Stop
    if ($Service.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Running) {
        throw "Служба установлена, но имеет статус $($Service.Status)."
    }
    if (-not [IO.File]::Exists((Join-Path $InstallRoot 'bin\CollegePortal.Gateway.Host.exe'))) {
        throw 'Установленный Gateway EXE отсутствует.'
    }
    Write-Host '[OK] install-all.cmd прошел SHA-256 и фактически установил/запустил службу CollegePortalGateway.'
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
