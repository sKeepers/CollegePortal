[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PackageRoot,
    [switch]$PreflightOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'Gateway-Common.ps1')

$InstallRoot = 'C:\CollegePortalGateway'
$ServiceName = 'CollegePortalGateway'
$AllowedPortalIp = '192.168.34.104'
$PackageRoot = [IO.Path]::GetFullPath($PackageRoot)
$SourceExe = Join-Path $PackageRoot 'bin\CollegePortal.Gateway.Host.exe'
$Manifest = Join-Path $PackageRoot 'SHA256SUMS'
$ConfigExample = Join-Path $PackageRoot 'config.example'
$ReportPath = Join-Path $InstallRoot 'diagnostics\installation-report.txt'
$StartedAt = [DateTime]::UtcNow
$Report = New-Object Collections.Generic.List[string]
$ExitCode = 0

function Add-Report([string]$Message) {
    $Line = ('{0} {1}' -f [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'), $Message)
    $Report.Add($Line)
    Write-Host $Message
}

function Assert-ExitCode([string]$Action) {
    if ($LASTEXITCODE -ne 0) { throw "$Action завершилось с кодом $LASTEXITCODE." }
}

try {
    Add-Report '[INFO] Начата проверка пакета CollegePortal Gateway.'
    if (-not (Test-GatewayAdministrator)) { throw 'Запустите install-all.cmd от имени администратора.' }
    if ([Environment]::OSVersion.Version -lt [Version]'6.1') { throw 'Требуется Windows 7 SP1 или новее.' }

    $Release = 0
    try { $Release = [int](Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full' -Name Release).Release } catch { }
    if ($Release -lt 528040) { throw ".NET Framework 4.8 не найден (Release=$Release)." }
    Add-Report "[OK] .NET Framework Release=$Release."

    foreach ($Required in @($SourceExe, $Manifest, $ConfigExample)) {
        if (-not [IO.File]::Exists($Required)) { throw "Обязательный файл пакета отсутствует: $Required" }
    }

    foreach ($Line in [IO.File]::ReadAllLines($Manifest)) {
        if ($Line -notmatch '^([0-9a-f]{64})  (.+)$') { throw "Некорректная строка SHA256SUMS: $Line" }
        $Expected = $Matches[1]
        $Relative = $Matches[2].Replace('/', '\')
        $File = Join-Path $PackageRoot $Relative
        if (-not [IO.File]::Exists($File)) { throw "Файл из SHA256SUMS отсутствует: $Relative" }
        if ((Get-CollegePortalSha256 $File) -ne $Expected) { throw "SHA-256 не совпадает: $Relative" }
    }
    Add-Report '[OK] SHA256SUMS проверен, обязательный EXE присутствует.'

    if ($PreflightOnly) {
        Add-Report '[OK] Предварительная проверка завершена. Система не изменялась.'
        exit 0
    }

    foreach ($Directory in @(
        $InstallRoot, "$InstallRoot\bin", "$InstallRoot\config", "$InstallRoot\logs",
        "$InstallRoot\cache", "$InstallRoot\backup", "$InstallRoot\diagnostics",
        "$InstallRoot\specs", "$InstallRoot\tools"
    )) {
        if (-not [IO.Directory]::Exists($Directory)) { [IO.Directory]::CreateDirectory($Directory) | Out-Null }
    }

    $Service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    $ServiceExists = ($null -ne $Service)
    if ($ServiceExists) {
        Add-Report '[INFO] Существующая служба найдена; выполняется безопасный repair.'
        if ($Service.Status -ne [System.ServiceProcess.ServiceControllerStatus]::Stopped) {
            Stop-Service -Name $ServiceName -Force
            $Service.WaitForStatus([System.ServiceProcess.ServiceControllerStatus]::Stopped, [TimeSpan]::FromSeconds(20))
        }
    }

    $Backup = Join-Path "$InstallRoot\backup" ([DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss'))
    if ([IO.Directory]::Exists("$InstallRoot\bin") -and (Get-ChildItem "$InstallRoot\bin" -File -ErrorAction SilentlyContinue)) {
        [IO.Directory]::CreateDirectory($Backup) | Out-Null
        Copy-Item -LiteralPath "$InstallRoot\bin" -Destination $Backup -Recurse -Force
        Add-Report "[OK] Предыдущие бинарные файлы сохранены в backup."
    }

    Copy-Item -LiteralPath $SourceExe -Destination "$InstallRoot\bin\CollegePortal.Gateway.Host.exe" -Force
    Get-ChildItem -LiteralPath $PackageRoot -File | Where-Object { $_.Extension -eq '.cmd' -or $_.Extension -eq '.ps1' } |
        Copy-Item -Destination "$InstallRoot\tools" -Force

    $PrivateConfig = "$InstallRoot\config\gateway.private.config"
    if (-not [IO.File]::Exists($PrivateConfig)) {
        Copy-Item -LiteralPath $ConfigExample -Destination $PrivateConfig
        Add-Report '[INFO] Создан новый private config из безопасного шаблона.'
    } else {
        Add-Report '[OK] Существующий private config сохранен.'
    }

    $ConfigText = [IO.File]::ReadAllText($PrivateConfig)
    if ($ConfigText -match '(?im)^SharedSecret\s*=\s*(CHANGE_ME[^\r\n]*|)\s*$') {
        $Bytes = New-Object byte[] 48
        $Rng = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $Rng.GetBytes($Bytes) } finally { $Rng.Dispose() }
        $GeneratedSecret = [Convert]::ToBase64String($Bytes)
        $ConfigText = [Text.RegularExpressions.Regex]::Replace($ConfigText, '(?im)^SharedSecret\s*=.*$', "SharedSecret=$GeneratedSecret")
        [IO.File]::WriteAllText($PrivateConfig, $ConfigText, (New-Object Text.UTF8Encoding($false)))
        Add-Report '[OK] Placeholder HMAC secret заменен случайным значением; значение не выводилось.'
    }
    if ($ConfigText -match '(?im)^AllowedPortalIps\s*=\s*192\.168\.34\.104\s*$') {
        $ConfigText = [Text.RegularExpressions.Regex]::Replace($ConfigText,
            '(?im)^AllowedPortalIps\s*=.*$', 'AllowedPortalIps=192.168.34.104,127.0.0.1,::1')
        [IO.File]::WriteAllText($PrivateConfig, $ConfigText, (New-Object Text.UTF8Encoding($false)))
        Add-Report '[OK] Allowlist дополнен только loopback-адресами для local health.'
    }

    $Config = Read-GatewayConfig $PrivateConfig
    if (-not $Config.ContainsKey('AllowedPortalIps')) { throw 'AllowedPortalIps отсутствует.' }
    $ConfiguredIps = @($Config['AllowedPortalIps'].Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    $ExpectedIps = @($AllowedPortalIp, '127.0.0.1', '::1')
    $UnexpectedIps = @($ConfiguredIps | Where-Object { $ExpectedIps -notcontains $_ })
    $MissingIps = @($ExpectedIps | Where-Object { $ConfiguredIps -notcontains $_ })
    if ($UnexpectedIps.Count -gt 0 -or $MissingIps.Count -gt 0) {
        throw "AllowedPortalIps должен содержать только $AllowedPortalIp и loopback 127.0.0.1/::1."
    }
    if (-not $Config.ContainsKey('FisTestEndpoint') -or $Config['FisTestEndpoint'] -ne 'http://10.0.3.1:8383/api/import/ImportService.svc') { throw 'Разрешен только подтвержденный адрес ФИС TEST :8383.' }
    if ($Config['FisProductionEnabled'] -ne 'false') { throw 'FisProductionEnabled должен быть false.' }
    if ($Config['EnableDangerousOperations'] -ne 'false') { throw 'EnableDangerousOperations должен быть false.' }

    & "$InstallRoot\bin\CollegePortal.Gateway.Host.exe" --check-config --config $PrivateConfig
    Assert-ExitCode 'Проверка private config'

    & icacls.exe $PrivateConfig /inheritance:r /grant:r 'SYSTEM:(F)' 'BUILTIN\Administrators:(F)' '*S-1-5-20:(R)' | Out-Null
    Assert-ExitCode 'Настройка ACL private config'
    foreach ($Writable in @('logs', 'cache', 'diagnostics', 'specs')) {
        & icacls.exe "$InstallRoot\$Writable" /grant:r 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)' '*S-1-5-20:(OI)(CI)(M)' | Out-Null
        Assert-ExitCode "Настройка ACL $Writable"
    }
    & icacls.exe "$InstallRoot\bin" /grant:r 'SYSTEM:(OI)(CI)(F)' 'BUILTIN\Administrators:(OI)(CI)(F)' '*S-1-5-20:(OI)(CI)(RX)' | Out-Null
    Assert-ExitCode 'Настройка ACL bin'

    & netsh.exe http delete urlacl url='http://+:8099/' | Out-Null
    & netsh.exe http add urlacl url='http://+:8099/' user='NT AUTHORITY\NETWORK SERVICE' | Out-Null
    Assert-ExitCode 'Настройка HTTP URL ACL'

    $TargetExe = "$InstallRoot\bin\CollegePortal.Gateway.Host.exe"
    $BinPath = '"' + $TargetExe + '" --config "' + $PrivateConfig + '"'
    if ($ServiceExists) {
        & sc.exe config $ServiceName "binPath= $BinPath" 'start= auto' 'obj= NT AUTHORITY\NetworkService' | Out-Null
        Assert-ExitCode 'Обновление службы'
    } else {
        & sc.exe create $ServiceName "binPath= $BinPath" 'start= auto' 'obj= NT AUTHORITY\NetworkService' 'DisplayName= CollegePortal Gateway' | Out-Null
        Assert-ExitCode 'Регистрация службы'
    }
    & sc.exe description $ServiceName 'CollegePortal Gateway for protected integrations (TEST only)' | Out-Null
    & sc.exe failure $ServiceName 'reset= 86400' 'actions= restart/5000/restart/15000/none/0' | Out-Null

    & netsh.exe advfirewall firewall delete rule name='CollegePortal Gateway DEV 8099' | Out-Null
    & netsh.exe advfirewall firewall add rule name='CollegePortal Gateway DEV 8099' dir=in action=allow protocol=TCP localport=8099 remoteip=$AllowedPortalIp profile=any enable=yes | Out-Null
    Assert-ExitCode 'Настройка Windows Firewall'
    Add-Report "[OK] Firewall: TCP 8099 разрешен только от $AllowedPortalIp."

    Start-Service -Name $ServiceName
    (Get-Service -Name $ServiceName).WaitForStatus([System.ServiceProcess.ServiceControllerStatus]::Running, [TimeSpan]::FromSeconds(20))
    Start-Sleep -Seconds 3
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$InstallRoot\tools\Test-GatewayHealth.ps1" -InstallRoot $InstallRoot
    Assert-ExitCode 'Проверка health'

    Add-Report "[OK] Служба установлена. binPath=$BinPath"
    Add-Report '[OK] Установка завершена. Production endpoint не использовался.'
}
catch {
    Add-Report ('[ОШИБКА] ' + $_.Exception.Message)
    Add-Report '[STOP-GATE] Переход к скачиванию контракта и SOAP запрещен.'
    try { & sc.exe stop $ServiceName | Out-Null } catch { }
    $ExitCode = 1
}
finally {
    try {
        if (-not [IO.Directory]::Exists([IO.Path]::GetDirectoryName($ReportPath))) { [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($ReportPath)) | Out-Null }
        $Report.Add(('duration_seconds={0}' -f [int]([DateTime]::UtcNow - $StartedAt).TotalSeconds))
        Write-GatewayUtf8 $ReportPath $Report.ToArray()
        Write-Host "Отчет: $ReportPath"
    } catch { Write-Warning 'Не удалось записать install report.' }
}

if ($ExitCode) { exit $ExitCode }
