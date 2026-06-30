param(
    [string]$AppName = "college-portal-frontend"
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$FrontendDir = Join-Path $ProjectRoot "frontend"
$ScaffoldDir = Join-Path $ProjectRoot ".scaffold"
$VueTmpDir = Join-Path $ScaffoldDir "vue"
$FrontendEnvExample = Join-Path $FrontendDir ".env.example"
$FrontendEnv = Join-Path $FrontendDir ".env"

if (Test-Path (Join-Path $FrontendDir "package.json")) {
    Write-Host "Vue/Vite already exists in frontend/. Nothing to do."
    exit 0
}

docker --version | Out-Null

New-Item -ItemType Directory -Force -Path $ScaffoldDir | Out-Null
if (Test-Path $VueTmpDir) {
    Remove-Item -Recurse -Force $VueTmpDir
}

docker run --rm `
    -v "${ScaffoldDir}:/workspace" `
    -w /workspace `
    node:22-alpine `
    sh -lc "npm create vite@latest vue -- --template vue && cd vue && npm install pinia vue-router @vitejs/plugin-vue tailwindcss @tailwindcss/vite"

$FrontendEnvExampleBackup = $null
if (Test-Path $FrontendEnvExample) {
    $FrontendEnvExampleBackup = Get-Content -Raw $FrontendEnvExample
}

Copy-Item -Path (Join-Path $VueTmpDir "*") -Destination $FrontendDir -Recurse -Force
Get-ChildItem -Path $VueTmpDir -Force | Where-Object { $_.Name -like ".*" } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $FrontendDir -Recurse -Force
}

if ($FrontendEnvExampleBackup) {
    Set-Content -Path $FrontendEnvExample -Value $FrontendEnvExampleBackup -NoNewline
}

if (Test-Path $FrontendEnvExample) {
    Copy-Item -Path $FrontendEnvExample -Destination $FrontendEnv -Force
}

Write-Host "Vue/Vite scaffold is ready in frontend/."
