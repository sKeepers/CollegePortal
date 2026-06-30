param(
    [string]$LaravelVersion = "^12.0"
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$BackendDir = Join-Path $ProjectRoot "backend"
$ScaffoldDir = Join-Path $ProjectRoot ".scaffold"
$LaravelTmpDir = Join-Path $ScaffoldDir "laravel"
$BackendEnvExample = Join-Path $BackendDir ".env.example"
$BackendEnv = Join-Path $BackendDir ".env"
$Dockerfile = Join-Path $BackendDir "Dockerfile"

if (Test-Path (Join-Path $BackendDir "artisan")) {
    Write-Host "Laravel already exists in backend/. Nothing to do."
    exit 0
}

docker --version | Out-Null

New-Item -ItemType Directory -Force -Path $ScaffoldDir | Out-Null
if (Test-Path $LaravelTmpDir) {
    Remove-Item -Recurse -Force $LaravelTmpDir
}

docker run --rm `
    -v "${ScaffoldDir}:/workspace" `
    -w /workspace `
    composer:2 `
    sh -lc "composer create-project laravel/laravel laravel '$LaravelVersion' --prefer-dist"

$DockerfileBackup = $null
$BackendEnvExampleBackup = $null
if (Test-Path $Dockerfile) {
    $DockerfileBackup = Get-Content -Raw $Dockerfile
}
if (Test-Path $BackendEnvExample) {
    $BackendEnvExampleBackup = Get-Content -Raw $BackendEnvExample
}

Copy-Item -Path (Join-Path $LaravelTmpDir "*") -Destination $BackendDir -Recurse -Force
Get-ChildItem -Path $LaravelTmpDir -Force | Where-Object { $_.Name -like ".*" } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $BackendDir -Recurse -Force
}

if ($DockerfileBackup) {
    Set-Content -Path $Dockerfile -Value $DockerfileBackup -NoNewline
}
if ($BackendEnvExampleBackup) {
    Set-Content -Path $BackendEnvExample -Value $BackendEnvExampleBackup -NoNewline
}

Copy-Item -Path $BackendEnvExample -Destination $BackendEnv -Force

docker compose run --rm backend php artisan key:generate
docker compose run --rm backend sh -lc "chmod -R a+rwX storage bootstrap/cache"

Write-Host "Laravel scaffold is ready in backend/."
