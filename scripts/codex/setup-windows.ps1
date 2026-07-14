[CmdletBinding()]
param()

$tools = @('git', 'gh', 'pwsh', 'python', 'node', 'npm', 'MSBuild.exe')
Write-Host 'Проверка Windows build host. Пакеты автоматически не устанавливаются.'
foreach ($tool in $tools) {
    $command = Get-Command $tool -ErrorAction SilentlyContinue
    if ($command) {
        Write-Host "[OK]   $tool -> $($command.Source)"
    } else {
        Write-Warning "[MISS] $tool"
    }
}
Write-Host 'Для отсутствующих инструментов используйте утвержденный корпоративный способ установки.'
