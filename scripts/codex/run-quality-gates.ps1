[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (& git rev-parse --show-toplevel).Trim()
Write-Host '[RUN] git diff --check'
& git -C $root diff --check
if ($LASTEXITCODE -ne 0) { throw 'git diff --check failed.' }

Write-Host '[RUN] forbidden-file check'
$bash = Get-Command 'C:\Program Files\Git\bin\bash.exe' -ErrorAction SilentlyContinue
if ($bash) {
    & $bash.Source "$root/scripts/security/check-forbidden-files.sh"
    if ($LASTEXITCODE -ne 0) { throw 'Forbidden-file check failed.' }
} else {
    Write-Warning 'Git Bash не найден; forbidden-file check пропущен.'
}

if ((Get-Command npm -ErrorAction SilentlyContinue) -and (Test-Path "$root/frontend/node_modules")) {
    Push-Location "$root/frontend"
    try { & npm run build; if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' } } finally { Pop-Location }
} else {
    Write-Warning 'npm или frontend/node_modules не найден; frontend build выполняйте на Linux DEV/CI.'
}

if ((Get-Command php -ErrorAction SilentlyContinue) -and (Test-Path "$root/backend/vendor")) {
    Push-Location "$root/backend"
    try { & php artisan test; if ($LASTEXITCODE -ne 0) { throw 'Backend tests failed.' } } finally { Pop-Location }
} else {
    Write-Warning 'php или backend/vendor не найден; backend tests выполняйте на Linux DEV/CI.'
}
