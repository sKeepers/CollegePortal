[CmdletBinding()]
param(
    [string]$OutputRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repository = (& git rev-parse --show-toplevel).Trim()
$gateway = Join-Path $repository 'integrations\collegeportal-gateway'
if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $repository '.ci-artifacts\gateway'
}
$bin = Join-Path $OutputRoot 'bin'
$package = Join-Path $OutputRoot 'package'
New-Item -ItemType Directory -Force -Path $bin, $package | Out-Null

$project = Join-Path $gateway 'src\CollegePortal.Gateway.Host\CollegePortal.Gateway.Host.csproj'
& msbuild $project /p:Configuration=Release "/p:OutputPath=$bin\"
if ($LASTEXITCODE -ne 0) { throw 'Gateway MSBuild failed.' }

$exe = Join-Path $bin 'CollegePortal.Gateway.exe'
if (-not (Test-Path -LiteralPath $exe)) { throw "Gateway EXE не найден: $exe" }

Copy-Item -LiteralPath $exe -Destination $package
Copy-Item -LiteralPath (Join-Path $gateway 'config.example') -Destination $package
Copy-Item -LiteralPath (Join-Path $gateway 'VERSION') -Destination $package
Copy-Item -LiteralPath (Join-Path $gateway 'README.md') -Destination $package
Copy-Item -Recurse -LiteralPath (Join-Path $gateway 'packaging') -Destination $package
if (Test-Path (Join-Path $gateway 'docs')) {
    Copy-Item -Recurse -LiteralPath (Join-Path $gateway 'docs') -Destination $package
}

$version = (Get-Content -LiteralPath (Join-Path $gateway 'VERSION') -Raw).Trim()
$zip = Join-Path $OutputRoot "collegeportal-gateway-$version.zip"
Compress-Archive -Path (Join-Path $package '*') -DestinationPath $zip -Force
$hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
"$hash  $(Split-Path $zip -Leaf)" | Set-Content -LiteralPath "$zip.sha256" -Encoding ascii
Write-Host "Gateway package: $zip"
Write-Host "SHA-256: $hash"
