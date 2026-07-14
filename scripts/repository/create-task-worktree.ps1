[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9._-]+$')]
    [string]$TaskId,
    [string]$Branch,
    [string]$Base = 'origin/develop',
    [string]$WorktreeRoot = 'C:\!Projects\CollegePortal-worktrees'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'Git не найден в PATH.'
}

$repository = (& git rev-parse --show-toplevel).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Команда должна запускаться из Git-репозитория.' }

$dirty = @(& git -C $repository status --porcelain)
if ($dirty.Count -gt 0) {
    throw 'Текущий worktree содержит изменения. Создание нового worktree остановлено.'
}

if ([string]::IsNullOrWhiteSpace($Branch)) {
    $Branch = 'feature/' + $TaskId.ToLowerInvariant()
}

& git -C $repository fetch --all --prune
if ($LASTEXITCODE -ne 0) { throw 'git fetch завершился ошибкой.' }

& git -C $repository rev-parse --verify $Base *> $null
if ($LASTEXITCODE -ne 0) { throw "Базовая ссылка $Base не найдена." }

$target = Join-Path $WorktreeRoot $TaskId.ToLowerInvariant()
if (Test-Path -LiteralPath $target) { throw "Путь уже существует: $target" }
New-Item -ItemType Directory -Path $WorktreeRoot -Force | Out-Null

& git -C $repository show-ref --verify --quiet "refs/heads/$Branch"
$branchExists = $LASTEXITCODE -eq 0
if ($branchExists) {
    & git -C $repository worktree add $target $Branch
} else {
    & git -C $repository worktree add -b $Branch $target $Base
}
if ($LASTEXITCODE -ne 0) { throw 'Не удалось создать worktree.' }

$head = (& git -C $target rev-parse HEAD).Trim()
Write-Host "Worktree: $target"
Write-Host "Branch:   $Branch"
Write-Host "HEAD:     $head"
