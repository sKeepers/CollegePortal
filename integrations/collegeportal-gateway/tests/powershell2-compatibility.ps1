[CmdletBinding()]
param(
    [string]$ScriptsPath = (Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..\packaging\windows')
)

$ErrorActionPreference = 'Stop'
$ScriptsPath = [IO.Path]::GetFullPath($ScriptsPath)
if (-not [IO.Directory]::Exists($ScriptsPath)) { throw "Каталог PowerShell-скриптов не найден: $ScriptsPath" }

$Rules = @(
    @{ Pattern = '(?i)\.Dispose\s*\('; Message = 'Dispose() заменяется на Clear()/Close() для Windows PowerShell 2.0.' },
    @{ Pattern = '(?i)\bGet-FileHash\b'; Message = 'Get-FileHash появился после PowerShell 2.0.' },
    @{ Pattern = '(?i)\bGet-Content\b[^\r\n]*\s-(Raw|Tail)\b'; Message = 'Get-Content -Raw/-Tail появился после PowerShell 2.0.' },
    @{ Pattern = '(?i)\bGet-ChildItem\b[^\r\n]*\s-(File|Directory)\b'; Message = 'Get-ChildItem -File/-Directory появился после PowerShell 2.0.' },
    @{ Pattern = '(?i)\.CopyTo\s*\('; Message = 'Stream.CopyTo недоступен в CLR 2.0, используемом PowerShell 2.0.' },
    @{ Pattern = '(?i)\$PSScriptRoot\b'; Message = '$PSScriptRoot появился в PowerShell 3.0.' },
    @{ Pattern = '(?i)\b(Invoke-WebRequest|Invoke-RestMethod|ConvertFrom-Json|ConvertTo-Json)\b'; Message = 'Команда недоступна в PowerShell 2.0.' },
    @{ Pattern = '(?i)(^|\s)-(in|notin)\s'; Message = 'Операторы -in/-notin появились после PowerShell 2.0.' },
    @{ Pattern = '(?i)\[(ordered|pscustomobject)\]'; Message = 'Литерал типа появился после PowerShell 2.0.' },
    @{ Pattern = '(?i)::new\s*\('; Message = 'Синтаксис ::new() недоступен в PowerShell 2.0.' },
    @{ Pattern = '(?i)\.(ForEach|Where)\s*\('; Message = 'Методы коллекций .ForEach()/.Where() недоступны в PowerShell 2.0.' },
    @{ Pattern = '(?i)\[Xml\.DtdProcessing\]'; Message = 'DtdProcessing требует CLR 4; используйте XmlReaderSettings.ProhibitDtd.' }
)

$Failures = New-Object Collections.Generic.List[string]
$Files = @(Get-ChildItem -LiteralPath $ScriptsPath -Filter '*.ps1' | Where-Object { -not $_.PSIsContainer } | Sort-Object Name)
if ($Files.Count -eq 0) { throw "PowerShell-скрипты не найдены: $ScriptsPath" }

foreach ($File in $Files) {
    $Text = [IO.File]::ReadAllText($File.FullName)
    if ($Text -notmatch '(?im)^Set-StrictMode\s+-Version\s+2\s*$') {
        $Failures.Add("$($File.Name): отсутствует Set-StrictMode -Version 2.")
    }
    foreach ($Rule in $Rules) {
        $MatchesFound = [Text.RegularExpressions.Regex]::Matches($Text, $Rule.Pattern)
        foreach ($Match in $MatchesFound) {
            $Line = 1 + ([Text.RegularExpressions.Regex]::Matches($Text.Substring(0, $Match.Index), "`n")).Count
            $Failures.Add(('{0}:{1}: {2}' -f $File.Name, $Line, $Rule.Message))
        }
    }
    try { [scriptblock]::Create($Text) | Out-Null } catch { $Failures.Add("$($File.Name): parser: $($_.Exception.Message)") }
}


$CmdFiles = @(Get-ChildItem -LiteralPath $ScriptsPath -Filter '*.cmd' | Where-Object { -not $_.PSIsContainer } | Sort-Object Name)
foreach ($File in @($Files + $CmdFiles)) {
    $Text = [IO.File]::ReadAllText($File.FullName)
    $PowerShellMatches = [Text.RegularExpressions.Regex]::Matches($Text, '(?im)^.*powershell\.exe.*$')
    foreach ($Match in $PowerShellMatches) {
        $LineText = $Match.Value
        if ($LineText -notmatch '(?i)-NonInteractive\b' -or $LineText -notmatch '(?i)-InputFormat\s+None\b') {
            $Line = 1 + ([Text.RegularExpressions.Regex]::Matches($Text.Substring(0, $Match.Index), "`n")).Count
            $Failures.Add(('{0}:{1}: powershell.exe must use -NonInteractive -InputFormat None for Windows 7 OpenSSH.' -f $File.Name, $Line))
        }
    }
}
if ($Failures.Count -gt 0) {
    $Failures | ForEach-Object { Write-Host "[FAIL] $_" }
    throw "Найдены несовместимости Windows PowerShell 2.0: $($Failures.Count)."
}

$Common = Join-Path $ScriptsPath 'Gateway-Common.ps1'
if (-not [IO.File]::Exists($Common)) { throw "Gateway-Common.ps1 отсутствует: $Common" }
. $Common

$Temp = Join-Path ([IO.Path]::GetTempPath()) ('collegeportal-gateway-ps2-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Temp) | Out-Null
try {
    $Fixture = Join-Path $Temp 'sha256-fixture.txt'
    [IO.File]::WriteAllText($Fixture, 'CollegePortal Gateway PS2', (New-Object Text.UTF8Encoding($false)))
    $ActualHash = Get-CollegePortalSha256 $Fixture
    if ($ActualHash -ne 'fd2b92f3cc22b09b3b1e9bd69928414f2591863d7c22cd353d2130637bd16635') {
        throw "SHA-256 helper вернул неожиданный результат: $ActualHash"
    }

    $InputBytes = [Text.Encoding]::UTF8.GetBytes('stream-copy-fixture')
    $Input = New-Object IO.MemoryStream(,$InputBytes)
    $Output = New-Object IO.MemoryStream
    try {
        Copy-GatewayStream $Input $Output
        $Copied = [Text.Encoding]::UTF8.GetString($Output.ToArray())
        if ($Copied -ne 'stream-copy-fixture') { throw 'Copy-GatewayStream повредил данные.' }
    }
    finally {
        $Input.Close()
        $Output.Close()
    }

    $Log = Join-Path $Temp 'tail.log'
    [IO.File]::WriteAllLines($Log, @('one', 'two', 'three', 'four'), (New-Object Text.UTF8Encoding($false)))
    $Tail = @(Get-GatewayFileTail $Log 2)
    if ($Tail.Count -ne 2 -or $Tail[0] -ne 'three' -or $Tail[1] -ne 'four') {
        throw 'Get-GatewayFileTail вернул неверные строки.'
    }

    $RandomBytes = New-GatewayRandomBytes 48
    if (-not ($RandomBytes -is [byte[]]) -or $RandomBytes.Length -ne 48) {
        throw 'New-GatewayRandomBytes вернул неверный тип или длину.'
    }

    $ConfigPath = Join-Path $Temp 'gateway.private.config'
    $ConfigLines = @(
        '# comment',
        '',
        'KEY=value',
        'URL=http://10.0.3.1:8383/api/import/ImportService.svc?x=1',
        'SECRET=abc=def==',
        'EMPTY=',
        ' KEY_WITH_SPACES =value with spaces',
        'RUSSIAN=тестовое значение',
        'AllowedPortalIps=192.168.34.104,127.0.0.1,::1'
    )
    [IO.File]::WriteAllText($ConfigPath, ([char]0xFEFF + ($ConfigLines -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))
    $Config = Read-GatewayConfig $ConfigPath
    if ($Config['KEY'] -ne 'value') { throw 'Read-GatewayConfig повредил простое KEY=VALUE.' }
    if ($Config['URL'] -ne 'http://10.0.3.1:8383/api/import/ImportService.svc?x=1') { throw 'Read-GatewayConfig повредил URL со знаками =.' }
    if ($Config['SECRET'] -ne 'abc=def==') { throw 'Read-GatewayConfig повредил Base64/HMAC value со знаками =.' }
    if ($Config['EMPTY'] -ne '') { throw 'Read-GatewayConfig не поддержал пустое значение.' }
    if ($Config['KEY_WITH_SPACES'] -ne 'value with spaces') { throw 'Read-GatewayConfig неверно обработал пробелы вокруг ключа.' }
    if ($Config['RUSSIAN'] -ne 'тестовое значение') { throw 'Read-GatewayConfig повредил русское значение.' }
    if ($Config['AllowedPortalIps'] -ne '192.168.34.104,127.0.0.1,::1') { throw 'Read-GatewayConfig повредил список IP.' }

    $InvalidConfig = Join-Path $Temp 'invalid.config'
    [IO.File]::WriteAllText($InvalidConfig, "KEY=value`r`nBROKEN_LINE`r`n", (New-Object Text.UTF8Encoding($false)))
    $InvalidRejected = $false
    try { Read-GatewayConfig $InvalidConfig | Out-Null } catch { $InvalidRejected = $true }
    if (-not $InvalidRejected) { throw 'Read-GatewayConfig должен отклонять строку без разделителя =.' }

    $DuplicateConfig = Join-Path $Temp 'duplicate.config'
    [IO.File]::WriteAllText($DuplicateConfig, "KEY=value`r`nKEY=other`r`n", (New-Object Text.UTF8Encoding($false)))
    $DuplicateRejected = $false
    try { Read-GatewayConfig $DuplicateConfig | Out-Null } catch { $DuplicateRejected = $true }
    if (-not $DuplicateRejected) { throw 'Read-GatewayConfig должен отклонять дублирующий ключ.' }

    $EmptyKeyConfig = Join-Path $Temp 'empty-key.config'
    [IO.File]::WriteAllText($EmptyKeyConfig, "=value`r`n", (New-Object Text.UTF8Encoding($false)))
    $EmptyKeyRejected = $false
    try { Read-GatewayConfig $EmptyKeyConfig | Out-Null } catch { $EmptyKeyRejected = $true }
    if (-not $EmptyKeyRejected) { throw 'Read-GatewayConfig должен отклонять пустой ключ.' }
}
finally {
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}

Write-Host "[OK] Проверены $($Files.Count) поставляемых PowerShell-скриптов: Windows PowerShell 2.0 compatibility gate пройден."
