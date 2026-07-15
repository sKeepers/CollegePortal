param(
    [string]$RepoPath = "C:\!Projects\CollegePortal",
    [string]$RemoteUrl = "https://github.com/sKeepers/CollegePortal.git"
)

$ErrorActionPreference = "Stop"
$expectedRemote = "github.com/sKeepers/CollegePortal"
$legacyWindowsPath = "C:\!Projects\" + "college_portal"
$legacyWorktrees = "college" + "_portal-worktrees"
$legacyTmp = "college" + "_portal\tmp"
$allowedRoot = "C:\!Projects\CollegePortal"
$allowedWorktreeRoot = Join-Path $allowedRoot ".worktrees"
$pathPolicyMessage = "Использование устаревшего пути запрещено. Используйте C:\!Projects\CollegePortal."

function Assert-AllowedCollegePortalPath([string]$Path) {
    $fullPath = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    if ($fullPath.Equals($legacyWindowsPath, [StringComparison]::OrdinalIgnoreCase) -or
        $fullPath.StartsWith($legacyWindowsPath + "\", [StringComparison]::OrdinalIgnoreCase) -or
        $fullPath.IndexOf($legacyWorktrees, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
        $fullPath.IndexOf($legacyTmp, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
        Write-Error $pathPolicyMessage
    }
    if (-not $fullPath.Equals($allowedRoot, [StringComparison]::OrdinalIgnoreCase) -and
        -not $fullPath.StartsWith($allowedWorktreeRoot + "\", [StringComparison]::OrdinalIgnoreCase)) {
        Write-Error "Недопустимый Windows-каталог CollegePortal: $fullPath. Используйте $allowedRoot или $allowedWorktreeRoot\<branch>."
    }
}

Assert-AllowedCollegePortalPath $RepoPath

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    Write-Error "Git is not installed or not available in PATH."
}

if (-not (Test-Path -LiteralPath $RepoPath)) {
    $parent = Split-Path -Parent $RepoPath
    if ($parent -and -not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Force -Path $parent | Out-Null
    }
    git clone --branch develop $RemoteUrl $RepoPath
}

Push-Location $RepoPath
try {
    if (-not (Test-Path -LiteralPath ".git")) {
        Write-Error "Path is not a Git repository: $RepoPath"
    }

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
