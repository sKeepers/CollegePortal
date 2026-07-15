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
        'gateway-menu.cmd',
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
