$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
$Out = [System.IO.Path]::GetFullPath((Join-Path $Root "..\..\releases\collegeportal-gateway-$Version"))

function Get-Sha256Hex([string] $Path) {
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $stream = [System.IO.File]::OpenRead($Path)
        try {
            $bytes = $sha.ComputeHash($stream)
            return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
        } finally {
            $stream.Dispose()
        }
    } finally {
        $sha.Dispose()
    }
}

Remove-Item -LiteralPath $Out -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath "$Out.zip" -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $Out | Out-Null
if (-not (Test-Path -LiteralPath (Join-Path $Root 'bin\CollegePortal.Gateway.Host.exe'))) {
    throw 'Gateway executable is missing. Run build.cmd before package.cmd.'
}
Copy-Item -Recurse -Force (Join-Path $Root 'bin') $Out
Copy-Item -Recurse -Force (Join-Path $Root 'packaging') $Out
if (Test-Path -LiteralPath (Join-Path $Root 'docs')) {
    Copy-Item -Recurse -Force (Join-Path $Root 'docs') $Out
}
Copy-Item -Force (Join-Path $Root 'config.example') $Out
Copy-Item -Force (Join-Path $Root 'README.md') $Out
Copy-Item -Force (Join-Path $Root 'VERSION') $Out
Get-ChildItem -LiteralPath $Out -Recurse -File |
    Where-Object { $_.Name -ne 'SHA256SUMS' } |
    Sort-Object FullName |
    ForEach-Object {
        $relative = $_.FullName.Substring($Out.Length + 1).Replace('\', '/')
        $hash = Get-Sha256Hex $_.FullName
        "$hash  $relative"
    } | Set-Content -Encoding ASCII (Join-Path $Out 'SHA256SUMS')
Compress-Archive -Force -Path (Join-Path $Out '*') -DestinationPath "$Out.zip"
"$(Get-Sha256Hex "$Out.zip")  $(Split-Path "$Out.zip" -Leaf)"
