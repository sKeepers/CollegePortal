[CmdletBinding()]
param(
    [string]$OutputDirectory,
    [string]$ExecutablePath
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
if (-not $OutputDirectory) {
    $OutputDirectory = [IO.Path]::GetFullPath((Join-Path $Root '..\..\releases'))
}

$Executable = if ($ExecutablePath) { [IO.Path]::GetFullPath($ExecutablePath) } else { Join-Path $Root 'artifacts\Release\CollegePortal.Gateway.Host.exe' }
if (-not (Test-Path -LiteralPath $Executable -PathType Leaf)) {
    throw 'CollegePortal.Gateway.Host.exe отсутствует. Сначала выполните build.cmd.'
}

$Stage = Join-Path $OutputDirectory "collegeportal-gateway-$Version"
$Zip = "$Stage.zip"
$ExternalHash = "$Zip.sha256"

if (Test-Path -LiteralPath $Stage) { Remove-Item -LiteralPath $Stage -Recurse -Force }
if (Test-Path -LiteralPath $Zip) { Remove-Item -LiteralPath $Zip -Force }
New-Item -ItemType Directory -Path (Join-Path $Stage 'bin') -Force | Out-Null

Copy-Item -LiteralPath $Executable -Destination (Join-Path $Stage 'bin\CollegePortal.Gateway.Host.exe')
Copy-Item -LiteralPath (Join-Path $Root 'config.example') -Destination $Stage
Copy-Item -LiteralPath (Join-Path $Root 'README.md') -Destination $Stage
Copy-Item -LiteralPath (Join-Path $Root 'VERSION') -Destination $Stage
Get-ChildItem -LiteralPath (Join-Path $Root 'packaging\windows') -File |
    Where-Object { $_.Extension -in '.cmd', '.ps1' } |
    ForEach-Object {
        $Destination = Join-Path $Stage $_.Name
        if ($_.Extension -eq '.cmd') {
            $Text = [IO.File]::ReadAllText($_.FullName, [Text.Encoding]::UTF8)
            $Normalized = (($Text -replace "`r`n", "`n") -replace "`r", "`n") -replace "`n", "`r`n"
            [IO.File]::WriteAllText($Destination, $Normalized, (New-Object Text.UTF8Encoding($false)))
        } elseif ($_.Extension -eq '.ps1') {
            $Text = [IO.File]::ReadAllText($_.FullName, [Text.Encoding]::UTF8)
            $Normalized = (($Text -replace "`r`n", "`n") -replace "`r", "`n") -replace "`n", "`r`n"
            [IO.File]::WriteAllText($Destination, $Normalized, (New-Object Text.UTF8Encoding($true)))
        } else {
            Copy-Item -LiteralPath $_.FullName -Destination $Destination
        }
    }

$ForbiddenNames = @('gateway.private.config', '.env', 'credentials', 'secret', 'password', 'wsdl', 'xsd', 'disco', '.log')
$Forbidden = Get-ChildItem -LiteralPath $Stage -Recurse -File | Where-Object {
    $name = $_.Name.ToLowerInvariant()
    $ForbiddenNames | Where-Object { $name -eq $_ -or $name.EndsWith($_) }
}
if ($Forbidden) {
    throw "В пакет попали запрещенные файлы: $($Forbidden.FullName -join ', ')"
}

$Commit = 'unknown'
try { $Commit = (& git -C $Root rev-parse --short=12 HEAD 2>$null).Trim() } catch { }
@(
    "version=$Version"
    "commit=$Commit"
    "built_at_utc=$([DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'))"
    'target=.NET Framework 4.8 / Windows 7 SP1+ / Windows PowerShell 2.0+'
) | Set-Content -LiteralPath (Join-Path $Stage 'BUILD_INFO') -Encoding UTF8

$ManifestPath = Join-Path $Stage 'SHA256SUMS'
$ManifestLines = Get-ChildItem -LiteralPath $Stage -Recurse -File |
    Where-Object { $_.FullName -ne $ManifestPath } |
    Sort-Object FullName |
    ForEach-Object {
        $relative = $_.FullName.Substring($Stage.Length + 1).Replace('\', '/')
        $hash = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        "$hash  $relative"
    }
$ManifestLines | Set-Content -LiteralPath $ManifestPath -Encoding ASCII

Compress-Archive -Path (Join-Path $Stage '*') -DestinationPath $Zip -CompressionLevel Optimal
$ZipHash = (Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash.ToLowerInvariant()
"$ZipHash  $([IO.Path]::GetFileName($Zip))" | Set-Content -LiteralPath $ExternalHash -Encoding ASCII

Write-Host "Package: $Zip"
Write-Host "SHA-256: $ZipHash"
