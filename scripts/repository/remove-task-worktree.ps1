[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$target = (Resolve-Path -LiteralPath $Path).Path
$repository = (& git rev-parse --show-toplevel).Trim()
$worktrees = @(& git -C $repository worktree list --porcelain | Where-Object { $_ -like 'worktree *' } | ForEach-Object { $_.Substring(9) })
if ($worktrees.Count -lt 2) { throw 'Связанные worktree не найдены.' }
if ($target -eq $worktrees[0]) { throw 'Удаление основного worktree запрещено.' }
if ($target -notin $worktrees) { throw "Путь не зарегистрирован как worktree: $target" }

$dirty = @(& git -C $target status --porcelain)
if ($dirty.Count -gt 0) { throw 'Worktree содержит изменения. Сначала сохраните или удалите их вручную.' }

& git -C $repository worktree remove $target
if ($LASTEXITCODE -ne 0) { throw 'Git отказался удалить worktree.' }
Write-Host "Worktree удален: $target"
Write-Host 'Ветка сохранена и автоматически не удалялась.'
