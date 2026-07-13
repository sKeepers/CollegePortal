$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
$Out = Join-Path $Root "..\..\releases\fis-gateway-agent-$Version"
New-Item -ItemType Directory -Force -Path $Out | Out-Null
Copy-Item -Recurse -Force (Join-Path $Root 'bin\*') $Out -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $Root 'config.example') $Out
Copy-Item -Force (Join-Path $Root '*.cmd') $Out
Copy-Item -Force (Join-Path $Root 'README.md') $Out
Copy-Item -Force (Join-Path $Root 'VERSION') $Out
Compress-Archive -Force -Path (Join-Path $Out '*') -DestinationPath "$Out.zip"
Get-FileHash "$Out.zip" -Algorithm SHA256 | ForEach-Object { "$($_.Hash.ToLower())  $(Split-Path $_.Path -Leaf)" } | Set-Content -Encoding ASCII (Join-Path $Out 'SHA256SUMS')
