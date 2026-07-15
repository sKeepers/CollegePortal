[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath
)

$ErrorActionPreference = 'Stop'
$PackagePath = [IO.Path]::GetFullPath($PackagePath)
if (-not (Test-Path -LiteralPath $PackagePath -PathType Leaf)) { throw "Пакет не найден: $PackagePath" }

$Temp = Join-Path ([IO.Path]::GetTempPath()) ("collegeportal-gateway-verify-" + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $Temp | Out-Null
try {
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $Temp
    $Required = @(
        'bin\CollegePortal.Gateway.Host.exe',
        'config.example',
        'install-all.cmd',
        '01-install.cmd',
        'gateway-menu.cmd',
        'Gateway-Common.ps1',
        '04-health.cmd',
        '07-collect-diagnostics.cmd',
        '08-download-fis-contract.cmd',
        '09-import-fis-contract.cmd',
        'VERSION',
        'SHA256SUMS'
    )
    foreach ($Relative in $Required) {
        if (-not (Test-Path -LiteralPath (Join-Path $Temp $Relative) -PathType Leaf)) {
            throw "В пакете отсутствует обязательный файл: $Relative"
        }
    }

    $ForbiddenPatterns = @(
        'gateway.private.config', '*.env', '*.key', '*.pem', '*.pfx', '*.p12',
        '*.wsdl', '*.xsd', '*.disco', '*.log', '*credentials*', '*secret*'
    )
    foreach ($Pattern in $ForbiddenPatterns) {
        if (Get-ChildItem -LiteralPath $Temp -Recurse -File -Filter $Pattern) {
            throw "В пакете найден запрещенный файл по шаблону: $Pattern"
        }
    }

    $CmdFiles = Get-ChildItem -LiteralPath $Temp -File -Filter '*.cmd'
    foreach ($Cmd in $CmdFiles) {
        $Bytes = [IO.File]::ReadAllBytes($Cmd.FullName)
        if ($Bytes.Length -ge 3 -and $Bytes[0] -eq 0xEF -and $Bytes[1] -eq 0xBB -and $Bytes[2] -eq 0xBF) {
            throw "CMD содержит UTF-8 BOM: $($Cmd.Name)"
        }
        if ($Bytes.Length -ge 2 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xFE) {
            throw "CMD содержит UTF-16 LE BOM: $($Cmd.Name)"
        }
        if ($Bytes.Length -ge 2 -and $Bytes[0] -eq 0xFE -and $Bytes[1] -eq 0xFF) {
            throw "CMD содержит UTF-16 BE BOM: $($Cmd.Name)"
        }

        $Text = [Text.Encoding]::UTF8.GetString($Bytes)
        if ($Text -notmatch "`r`n") {
            throw "CMD не содержит CRLF-переносов: $($Cmd.Name)"
        }
        if ($Text -match "(?<!`r)`n") {
            throw "CMD содержит LF-only переносы: $($Cmd.Name)"
        }
    }

    $PowerShellFiles = Get-ChildItem -LiteralPath $Temp -File -Filter '*.ps1'
    foreach ($PowerShellFile in $PowerShellFiles) {
        $Bytes = [IO.File]::ReadAllBytes($PowerShellFile.FullName)
        if (-not ($Bytes.Length -ge 3 -and $Bytes[0] -eq 0xEF -and $Bytes[1] -eq 0xBB -and $Bytes[2] -eq 0xBF)) {
            throw "PS1 должен содержать UTF-8 BOM для Windows PowerShell 5: $($PowerShellFile.Name)"
        }

        $Text = [Text.Encoding]::UTF8.GetString($Bytes)
        if ($Text -notmatch "`r`n") {
            throw "PS1 не содержит CRLF-переносов: $($PowerShellFile.Name)"
        }
        if ($Text -match "(?<!`r)`n") {
            throw "PS1 содержит LF-only переносы: $($PowerShellFile.Name)"
        }
    }

    $InstallWrapper = [IO.File]::ReadAllText((Join-Path $Temp '01-install.cmd'), [Text.Encoding]::UTF8)
    if ($InstallWrapper -notmatch 'for %%I in \("%PACKAGE_ROOT%"\) do set "PACKAGE_ROOT=%%~fI"') {
        throw '01-install.cmd не канонизирует PackageRoot перед PowerShell invocation.'
    }
    if ($InstallWrapper -notmatch '-PreflightOnly') {
        throw '01-install.cmd не поддерживает безопасный --dry-run.'
    }

    $Manifest = Join-Path $Temp 'SHA256SUMS'
    foreach ($Line in Get-Content -LiteralPath $Manifest) {
        if ($Line -notmatch '^([0-9a-f]{64})  (.+)$') { throw "Некорректная строка SHA256SUMS: $Line" }
        $Expected = $Matches[1]
        $Relative = $Matches[2].Replace('/', '\')
        $File = Join-Path $Temp $Relative
        if (-not (Test-Path -LiteralPath $File -PathType Leaf)) { throw "Файл из SHA256SUMS отсутствует: $Relative" }
        $Actual = (Get-FileHash -LiteralPath $File -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($Actual -ne $Expected) { throw "SHA-256 не совпадает: $Relative" }
    }

    Write-Host "[OK] Пакет проверен: $PackagePath"
}
finally {
    if (Test-Path -LiteralPath $Temp) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
