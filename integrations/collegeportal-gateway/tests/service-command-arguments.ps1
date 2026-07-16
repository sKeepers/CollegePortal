[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
. (Join-Path $Root 'packaging\windows\Gateway-Common.ps1')

function Assert-Equal([object]$Expected, [object]$Actual, [string]$CaseName) {
    if ($Expected -ne $Actual) {
        throw "${CaseName}: ожидалось <$Expected>, получено <$Actual>."
    }
}

function Assert-ArrayEqual([string[]]$Expected, [string[]]$Actual, [string]$CaseName) {
    Assert-Equal $Expected.Count $Actual.Count "$CaseName count"
    for ($Index = 0; $Index -lt $Expected.Count; $Index++) {
        Assert-Equal $Expected[$Index] $Actual[$Index] "$CaseName[$Index]"
    }
}

function Assert-NoPackedScOption([string[]]$Arguments, [string]$CaseName) {
    foreach ($Argument in $Arguments) {
        if ($Argument -match '^(binPath|start|obj|DisplayName|reset|actions)=\s+\S') {
            throw "${CaseName}: sc.exe option and value must be separate argv items: <$Argument>."
        }
    }
}

$ServiceName = 'CollegePortalGateway'
$BinPath = Get-GatewayServiceBinPath -TargetExe 'C:\CollegePortalGateway\bin\CollegePortal.Gateway.Host.exe' -PrivateConfig 'C:\CollegePortalGateway\config\gateway.private.config'

$CreateExpected = @(
    $ServiceName,
    'binPath=', $BinPath,
    'start=', 'auto',
    'obj=', 'NT AUTHORITY\NetworkService',
    'DisplayName=', 'CollegePortal Gateway'
)
$ConfigExpected = @(
    $ServiceName,
    'binPath=', $BinPath,
    'start=', 'auto',
    'obj=', 'NT AUTHORITY\NetworkService'
)
$FailureExpected = @(
    $ServiceName,
    'reset=', '86400',
    'actions=', 'restart/5000/restart/15000/none/0'
)

$CreateActual = @(Get-GatewayServiceCreateArguments -ServiceName $ServiceName -BinPath $BinPath)
$ConfigActual = @(Get-GatewayServiceConfigArguments -ServiceName $ServiceName -BinPath $BinPath)
$FailureActual = @(Get-GatewayServiceFailureArguments -ServiceName $ServiceName)

Assert-ArrayEqual $CreateExpected $CreateActual 'create'
Assert-ArrayEqual $ConfigExpected $ConfigActual 'config'
Assert-ArrayEqual $FailureExpected $FailureActual 'failure'
Assert-NoPackedScOption $CreateActual 'create'
Assert-NoPackedScOption $ConfigActual 'config'
Assert-NoPackedScOption $FailureActual 'failure'

Write-Host '[OK] Аргументы sc.exe для create/config/failure передаются как отдельные argv-элементы.'
Assert-Equal 'C:\CollegePortalGateway\bin\CollegePortal.Gateway.Host.exe --config C:\CollegePortalGateway\config\gateway.private.config' $BinPath 'windows7 binPath'
if ($BinPath -match '"') { throw 'Windows 7 sc.exe regression: service binPath must not contain quotes for fixed no-space install root.' }

$WhitespaceRejected = $false
try {
    Get-GatewayServiceBinPath -TargetExe 'C:\College Portal Gateway\bin\CollegePortal.Gateway.Host.exe' -PrivateConfig 'C:\CollegePortalGateway\config\gateway.private.config' | Out-Null
} catch { $WhitespaceRejected = $true }
if (-not $WhitespaceRejected) { throw 'Whitespace service paths must be rejected until a Windows 7 sc.exe-safe quoting strategy is implemented.' }
