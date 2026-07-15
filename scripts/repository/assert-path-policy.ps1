[CmdletBinding()]
param(
    [string]$RepoPath = (Get-Location).ProviderPath
)

$ErrorActionPreference = "Stop"

$legacyWindowsPath = "C:\!Projects\" + "college_portal"
$legacyWorktrees = "college" + "_portal-worktrees"
$legacyTmp = "college" + "_portal\tmp"
$message = "Использование устаревшего пути запрещено. Используйте C:\!Projects\CollegePortal."

function Assert-AllowedCollegePortalPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $fullPath = [IO.Path]::GetFullPath($Path)
    $normalized = $fullPath.TrimEnd('\')
    $allowedRoot = "C:\!Projects\CollegePortal"
    $allowedWorktreeRoot = Join-Path $allowedRoot ".worktrees"

    if ($normalized.Equals($legacyWindowsPath, [StringComparison]::OrdinalIgnoreCase) -or
        $normalized.StartsWith($legacyWindowsPath + "\", [StringComparison]::OrdinalIgnoreCase) -or
        $normalized.IndexOf($legacyWorktrees, [StringComparison]::OrdinalIgnoreCase) -ge 0 -or
        $normalized.IndexOf($legacyTmp, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
        throw $message
    }

    if ($normalized.Equals($allowedRoot, [StringComparison]::OrdinalIgnoreCase) -or
        $normalized.StartsWith($allowedWorktreeRoot + "\", [StringComparison]::OrdinalIgnoreCase)) {
        return
    }

    throw "Недопустимый Windows-каталог CollegePortal: $fullPath. Используйте $allowedRoot или $allowedWorktreeRoot\<branch>."
}

function Assert-RepositoryTextPathPolicy {
    param([Parameter(Mandatory = $true)][string]$Path)

    Push-Location $Path
    try {
        $patterns = @($legacyWindowsPath, $legacyWorktrees, $legacyTmp)
        $failed = $false
        foreach ($pattern in $patterns) {
            $matches = @(git grep -n -I -F $pattern -- . 2>$null)
            if ($matches.Count -gt 0) {
                $matches | ForEach-Object { Write-Error $_ -ErrorAction Continue }
                $failed = $true
            }
        }
        if ($failed) { throw $message }
    }
    finally {
        Pop-Location
    }
}

Assert-AllowedCollegePortalPath -Path $RepoPath
Assert-RepositoryTextPathPolicy -Path $RepoPath
Write-Host "[OK] CollegePortal path policy: no forbidden legacy Windows paths found."
