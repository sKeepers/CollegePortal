$ErrorActionPreference = "Stop"
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$RootEnvExample = Join-Path $ProjectRoot ".env.example"
$RootEnv = Join-Path $ProjectRoot ".env"

if ((Test-Path $RootEnvExample) -and -not (Test-Path $RootEnv)) {
    Copy-Item -Path $RootEnvExample -Destination $RootEnv
}

& (Join-Path $PSScriptRoot "init-laravel.ps1")
& (Join-Path $PSScriptRoot "init-frontend.ps1")

docker compose build

Write-Host "Project scaffold is ready."
Write-Host "Run: docker compose up -d"
