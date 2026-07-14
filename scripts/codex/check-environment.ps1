[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (& git rev-parse --show-toplevel).Trim()
Write-Host "Host:       $env:COMPUTERNAME"
Write-Host "Repository: $root"
Write-Host "Branch:     $((& git -C $root branch --show-current).Trim())"
Write-Host "HEAD:       $((& git -C $root rev-parse HEAD).Trim())"
$status = @(& git -C $root status --porcelain)
Write-Host "Working tree: $(if ($status.Count -eq 0) { 'clean' } else { 'dirty' })"
& "$PSScriptRoot\setup-windows.ps1"
