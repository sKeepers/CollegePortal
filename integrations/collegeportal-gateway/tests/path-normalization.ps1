[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
. (Join-Path $Root 'packaging\windows\Gateway-Common.ps1')

function Assert-Equal([string]$Expected, [string]$Actual, [string]$CaseName) {
    if (-not [string]::Equals($Expected, $Actual, [StringComparison]::OrdinalIgnoreCase)) {
        throw "${CaseName}: ожидалось <$Expected>, получено <$Actual>."
    }
}

function Assert-Rejected([scriptblock]$Action, [string]$ExpectedMessage, [string]$CaseName) {
    try {
        & $Action | Out-Null
    } catch {
        if ($_.Exception.Message -notlike "*$ExpectedMessage*") {
            throw "${CaseName}: неожиданное сообщение: $($_.Exception.Message)"
        }
        return
    }
    throw "${CaseName}: ожидался отказ, но путь был принят."
}

$Cases = @(
    @('C:\Tools\collegeportal-gateway', 'C:\Tools\collegeportal-gateway'),
    @('C:\Tools\collegeportal-gateway\', 'C:\Tools\collegeportal-gateway'),
    @('C:\Folder With Spaces\Gateway', 'C:\Folder With Spaces\Gateway'),
    @('C:\Folder With Spaces\Gateway\', 'C:\Folder With Spaces\Gateway'),
    @('C:\', 'C:\')
)
foreach ($Case in $Cases) {
    $Actual = Normalize-GatewayLocalPath -Path $Case[0] -ExpectedType Directory -ParameterName 'TestPath'
    Assert-Equal $Case[1] $Actual $Case[0]
}

$Temp = Join-Path ([IO.Path]::GetTempPath()) ('CollegePortal Gateway Paths ' + [Guid]::NewGuid().ToString('N'))
$Package = Join-Path $Temp 'gateway'
$Sibling = Join-Path $Temp 'sibling'
[IO.Directory]::CreateDirectory((Join-Path $Package 'bin')) | Out-Null
[IO.Directory]::CreateDirectory($Sibling) | Out-Null
$Binary = Join-Path $Package 'bin\CollegePortal.Gateway.Host.exe'
[IO.File]::WriteAllText($Binary, 'fixture', (New-Object Text.UTF8Encoding($false)))
$ExpectedHash = Get-CollegePortalSha256 $Binary
[IO.File]::WriteAllText((Join-Path $Package 'SHA256SUMS'), "$ExpectedHash  bin/CollegePortal.Gateway.Host.exe", (New-Object Text.UTF8Encoding($false)))

try {
    Push-Location $Temp
    try {
        Assert-Equal $Package (Normalize-GatewayLocalPath -Path '.\gateway' -ExpectedType Directory -MustExist -ParameterName 'RelativePath') '.\gateway'
        Assert-Equal $Sibling (Normalize-GatewayLocalPath -Path '.\gateway\..\sibling' -ExpectedType Directory -MustExist -ParameterName 'RelativeParentPath') '..\gateway'
    } finally {
        Pop-Location
    }

    $ResolvedPackage = Normalize-GatewayLocalPath -Path ($Package + '\') -ExpectedType Directory -MustExist -ParameterName 'PackageRoot'
    $ResolvedBinary = Normalize-GatewayLocalPath -Path (Join-Path $ResolvedPackage 'bin\CollegePortal.Gateway.Host.exe') -ExpectedType File -MustExist -ParameterName 'BinaryPath'
    Assert-Equal $Package $ResolvedPackage 'fixture PackageRoot'
    Assert-Equal $Binary $ResolvedBinary 'fixture BinaryPath'
    Assert-Equal $ExpectedHash (Get-CollegePortalSha256 $ResolvedBinary) 'fixture SHA-256'
    Assert-Equal (Join-Path $ResolvedPackage 'target') (Normalize-GatewayLocalPath -Path (Join-Path $ResolvedPackage 'target\') -ExpectedType Directory -ParameterName 'TargetPath') 'target directory'

    Assert-Rejected { Normalize-GatewayLocalPath -Path $null -ParameterName 'NullPath' } 'не задан' 'null'
    Assert-Rejected { Normalize-GatewayLocalPath -Path '   ' -ParameterName 'EmptyPath' } 'не может быть пустым' 'empty'
    Assert-Rejected { Normalize-GatewayLocalPath -Path 'C:\bad<path' -ParameterName 'InvalidPath' } 'недопустимые символы' 'invalid character'
    Assert-Rejected { Normalize-GatewayLocalPath -Path ("C:\bad$([char]1)path") -ParameterName 'ControlPath' } 'управляющие символы' 'control character'
    Assert-Rejected { Normalize-GatewayLocalPath -Path 'C:\missing-gateway-path' -ExpectedType Directory -MustExist -ParameterName 'MissingPath' } 'не найден' 'missing path'
    Assert-Rejected { Normalize-GatewayLocalPath -Path $Binary -ExpectedType Directory -MustExist -ParameterName 'FileAsDirectory' } 'должен указывать каталог' 'file instead of directory'
    Assert-Rejected { Normalize-GatewayLocalPath -Path 'C:\Tools\gateway*' -ParameterName 'WildcardPath' } 'wildcard' 'wildcard'
    Assert-Rejected { Normalize-GatewayLocalPath -Path 'https://example.invalid/gateway' -ParameterName 'UriPath' } 'URI не поддерживается' 'URI'
    Assert-Rejected { Normalize-GatewayLocalPath -Path '\\server\share\gateway' -ParameterName 'UncPath' } 'UNC-путь' 'UNC'
    Assert-Rejected { Normalize-GatewayLocalPath -Path 'C:\Tools\collegeportal-gateway"' -ParameterName 'QuotedPath' } 'недопустимые символы' 'trailing quote regression'

    Write-Host '[OK] Нормализация путей: локальные, root, spaces, relative и негативные сценарии проверены.'
} finally {
    if ([IO.Directory]::Exists($Temp)) { Remove-Item -LiteralPath $Temp -Recurse -Force }
}
