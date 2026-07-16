[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) '..'))
$ScriptsPath = Join-Path $Root 'packaging\windows'
. (Join-Path $ScriptsPath 'Gateway-Common.ps1')

function Assert-Equal([object]$Expected, [object]$Actual, [string]$CaseName) {
    if ($Expected -ne $Actual) {
        throw "${CaseName}: expected <$Expected>, got <$Actual>."
    }
}

Assert-Equal '*S-1-5-18:(F)' (Get-GatewaySystemAce '(F)') 'system private config ACE'
Assert-Equal '*S-1-5-32-544:(F)' (Get-GatewayAdministratorsAce '(F)') 'administrators private config ACE'
Assert-Equal '*S-1-5-20:(R)' (Get-GatewayNetworkServiceAce '(R)') 'network service private config ACE'
Assert-Equal '*S-1-5-20:(OI)(CI)(M)' (Get-GatewayNetworkServiceAce '(OI)(CI)(M)') 'network service writable ACE'
Assert-Equal 'D:(A;;GX;;;NS)' (Get-GatewayUrlAclSddl) 'network service URL ACL SDDL'

$Rejected = $false
try { Get-GatewaySidAce 'S-1-5-21-1-2-3-1001' '(F)' | Out-Null } catch { $Rejected = $true }
if (-not $Rejected) { throw 'Get-GatewaySidAce must reject non-well-known account SID values.' }

$Rejected = $false
try { Get-GatewaySidAce 'S-1-5-18' 'F' | Out-Null } catch { $Rejected = $true }
if (-not $Rejected) { throw 'Get-GatewaySidAce must reject malformed rights expressions.' }

$Installer = Join-Path $ScriptsPath 'Install-Gateway.ps1'
$Text = [IO.File]::ReadAllText($Installer)
$ForbiddenAclLiterals = @(
    'SYSTEM:(F)',
    'SYSTEM:(OI)(CI)(F)',
    'BUILTIN\Administrators:(F)',
    'BUILTIN\Administrators:(OI)(CI)(F)',
    'Administrators:(F)',
    'Администраторы:(F)',
    'NetworkService:(R)',
    'Network Service:(R)',
    'NT AUTHORITY\NETWORK SERVICE'
)
foreach ($Literal in $ForbiddenAclLiterals) {
    if ($Text.IndexOf($Literal, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
        throw "Install-Gateway.ps1 contains localized ACL account literal: $Literal"
    }
}

if ($Text -notmatch 'Get-GatewaySystemAce' -or $Text -notmatch 'Get-GatewayAdministratorsAce' -or $Text -notmatch 'Get-GatewayNetworkServiceAce') {
    throw 'Install-Gateway.ps1 must use SID-based ACL helper functions.'
}
if ($Text -notmatch 'Get-GatewayUrlAclSddl') {
    throw 'Install-Gateway.ps1 must use SDDL-based URL ACL helper.'
}

Write-Host '[OK] Gateway ACL uses well-known SID tokens and avoids localized account names in installer ACL operations.'
$ServiceBinPath = Get-GatewayServiceBinPath -TargetExe 'C:\CollegePortalGateway\bin\CollegePortal.Gateway.Host.exe' -PrivateConfig 'C:\CollegePortalGateway\config\gateway.private.config'
if ($ServiceBinPath -match '"') { throw 'Service binPath for Windows 7 must avoid embedded quotes with fixed no-space install root.' }
