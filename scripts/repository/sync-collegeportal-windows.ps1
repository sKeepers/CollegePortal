param(
    [string]$RepoPath = "C:\!Projects\college_portal"
)

$ErrorActionPreference = "Stop"
$expectedRemote = "github.com/sKeepers/CollegePortal"

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Error "Git is not installed or not available in PATH."
}

if (-not (Test-Path -LiteralPath $RepoPath)) {
    Write-Error "Repository path not found: $RepoPath"
}

Push-Location $RepoPath
try {
    $remote = git remote get-url origin 2>$null
    if (-not $remote -or $remote -notlike "*$expectedRemote*") {
        Write-Error "Unexpected origin remote: $remote"
    }

    Write-Host "Repository: $RepoPath"
    Write-Host "Remote: $remote"
    Write-Host "Branch: $(git branch --show-current)"
    Write-Host "HEAD: $(git rev-parse --short HEAD)"
    git status --short

    $dirty = git status --porcelain
    if ($dirty) {
        Write-Error "Working tree is dirty. Refusing to pull. Commit/stash/review changes manually first."
    }

    git fetch --all --prune
    git checkout develop
    git pull --ff-only origin develop

    Write-Host "Updated branch: $(git branch --show-current)"
    Write-Host "Updated HEAD: $(git rev-parse --short HEAD)"
    $aheadBehind = git rev-list --left-right --count HEAD...@{upstream}
    Write-Host "Ahead/behind: $aheadBehind"
    git status --short
}
finally {
    Pop-Location
}
