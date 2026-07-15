[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath
)

$ErrorActionPreference = 'Stop'
$PackagePath = [IO.Path]::GetFullPath($PackagePath)
if (-not [IO.File]::Exists($PackagePath)) { throw "Пакет не найден: $PackagePath" }

$Temp = Join-Path ([IO.Path]::GetTempPath()) ('CollegePortal Gateway Preflight ' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Temp) | Out-Null

try {
    Expand-Archive -LiteralPath $PackagePath -DestinationPath $Temp
    $Installer = Join-Path $Temp 'install-all.cmd'
    if (-not [IO.File]::Exists($Installer)) { throw 'install-all.cmd отсутствует в пакете.' }

    $StartInfo = New-Object Diagnostics.ProcessStartInfo
    $StartInfo.FileName = 'cmd.exe'
    $StartInfo.Arguments = '/d /v:on /c call "' + $Installer + '" --dry-run'
    $StartInfo.WorkingDirectory = $Temp
    $StartInfo.UseShellExecute = $false
    $StartInfo.CreateNoWindow = $true
    $StartInfo.RedirectStandardOutput = $true
    $StartInfo.RedirectStandardError = $true
    $StartInfo.StandardOutputEncoding = [Text.Encoding]::UTF8
    $StartInfo.StandardErrorEncoding = [Text.Encoding]::UTF8

    $Process = New-Object Diagnostics.Process
    $Process.StartInfo = $StartInfo
    [void]$Process.Start()
    $Stdout = $Process.StandardOutput.ReadToEnd()
    $Stderr = $Process.StandardError.ReadToEnd()
    $Process.WaitForExit()

    if ($Process.ExitCode -ne 0) {
        throw "install-all.cmd --dry-run завершился с кодом $($Process.ExitCode).`nSTDOUT:`n$Stdout`nSTDERR:`n$Stderr"
    }
    if (($Stdout + $Stderr) -match 'Illegal characters in path|is not recognized as an internal or external command') {
        throw "Обнаружена регрессия CMD/PackageRoot.`nSTDOUT:`n$Stdout`nSTDERR:`n$Stderr"
    }
    foreach ($Marker in @('[OK] PACKAGE_ROOT_VALIDATED', '[OK] PREFLIGHT_COMPLETED')) {
        if (-not $Stdout.Contains($Marker)) {
            throw "Dry-run не подтвердил обязательный этап $Marker.`nSTDOUT:`n$Stdout`nSTDERR:`n$Stderr"
        }
    }

    Write-Host '[OK] install-all.cmd --dry-run выполнен через cmd.exe из каталога с пробелами.'
} finally {
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
