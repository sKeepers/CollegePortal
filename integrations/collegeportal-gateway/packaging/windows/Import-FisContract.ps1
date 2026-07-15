[CmdletBinding()]
param([string]$InstallRoot = 'C:\CollegePortalGateway')

$ErrorActionPreference = 'Stop'
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'Gateway-Common.ps1')

$FisRoot = Join-Path $InstallRoot 'specs\fis'
$Source = Join-Path $FisRoot 'discovered'
$Manifest = Join-Path $Source 'download-manifest.tsv'
$Active = Join-Path $FisRoot 'active'
$Required = @(
    'import-service.single.wsdl',
    'import-service.wsdl.xml',
    'import-service-wrapper.xsd',
    'microsoft-serialization.xsd',
    'import-service.disco.xml'
)

function Load-SecureXml([string]$Path) {
    $Settings = New-Object Xml.XmlReaderSettings
    $Settings.DtdProcessing = [Xml.DtdProcessing]::Prohibit
    $Settings.XmlResolver = $null
    $Reader = [Xml.XmlReader]::Create($Path, $Settings)
    try {
        $Document = New-Object Xml.XmlDocument
        $Document.XmlResolver = $null
        $Document.Load($Reader)
        return $Document
    }
    finally { $Reader.Dispose() }
}

if (-not [IO.File]::Exists($Manifest)) { throw "Manifest скачивания отсутствует: $Manifest" }
$ExpectedHashes = @{}
foreach ($Line in [IO.File]::ReadAllLines($Manifest) | Select-Object -Skip 1) {
    $Columns = $Line.Split("`t")
    if ($Columns.Length -lt 7) { throw 'Manifest скачивания поврежден.' }
    $ExpectedHashes[$Columns[1]] = $Columns[5]
}

foreach ($Name in $Required) {
    $Path = Join-Path $Source $Name
    if (-not [IO.File]::Exists($Path)) { throw "Файл контракта отсутствует: $Name" }
    if (-not $ExpectedHashes.ContainsKey($Name)) { throw "SHA-256 отсутствует в manifest: $Name" }
    if ((Get-CollegePortalSha256 $Path) -ne $ExpectedHashes[$Name]) { throw "SHA-256 не совпадает: $Name" }
    Load-SecureXml $Path | Out-Null
}

$WsdlPath = Join-Path $Source 'import-service.single.wsdl'
$Wsdl = Load-SecureXml $WsdlPath
$Manager = New-Object Xml.XmlNamespaceManager($Wsdl.NameTable)
$Manager.AddNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/')
$Manager.AddNamespace('soap11', 'http://schemas.xmlsoap.org/wsdl/soap/')
$Manager.AddNamespace('soap12', 'http://schemas.xmlsoap.org/wsdl/soap12/')
$Manager.AddNamespace('wsp', 'http://schemas.xmlsoap.org/ws/2004/09/policy')

$Definitions = $Wsdl.SelectSingleNode('/wsdl:definitions', $Manager)
if ($null -eq $Definitions) { throw 'singleWsdl не содержит wsdl:definitions.' }
$TargetNamespace = $Definitions.GetAttribute('targetNamespace')
$Services = @($Wsdl.SelectNodes('//wsdl:service', $Manager) | ForEach-Object { $_.GetAttribute('name') })
$Bindings = @($Wsdl.SelectNodes('//wsdl:binding', $Manager) | ForEach-Object { $_.GetAttribute('name') })
$Ports = @($Wsdl.SelectNodes('//wsdl:service/wsdl:port', $Manager) | ForEach-Object { $_.GetAttribute('name') })
$Soap11Bindings = @($Wsdl.SelectNodes('//wsdl:binding/soap11:binding', $Manager)).Count
$Soap12Bindings = @($Wsdl.SelectNodes('//wsdl:binding/soap12:binding', $Manager)).Count
$Operations = New-Object Collections.Generic.List[string]
foreach ($Operation in $Wsdl.SelectNodes('//wsdl:binding/wsdl:operation', $Manager)) {
    $ActionNode = $Operation.SelectSingleNode('soap11:operation|soap12:operation', $Manager)
    $Action = if ($null -ne $ActionNode) { $ActionNode.GetAttribute('soapAction') } else { '' }
    $Operations.Add(($Operation.GetAttribute('name') + "`t" + $Action))
}
$Faults = @($Wsdl.SelectNodes('//wsdl:fault', $Manager) | ForEach-Object { $_.GetAttribute('name') } | Sort-Object -Unique)
$PolicyCount = @($Wsdl.SelectNodes('//*[local-name()="Policy" or local-name()="PolicyReference"]')).Count

$Stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$Snapshot = Join-Path $FisRoot ('active-' + $Stamp)
[IO.Directory]::CreateDirectory($Snapshot) | Out-Null
foreach ($Name in $Required) { Copy-Item -LiteralPath (Join-Path $Source $Name) -Destination $Snapshot }
Copy-Item -LiteralPath $Manifest -Destination $Snapshot

$Analysis = New-Object Collections.Generic.List[string]
$Analysis.Add('approval_status=unapproved_discovered')
$Analysis.Add('soap_calls_allowed=false')
$Analysis.Add(('analyzed_at_utc={0}' -f [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')))
$Analysis.Add(('target_namespace={0}' -f $TargetNamespace))
$Analysis.Add(('services={0}' -f ($Services -join ',')))
$Analysis.Add(('bindings={0}' -f ($Bindings -join ',')))
$Analysis.Add(('ports={0}' -f ($Ports -join ',')))
$Analysis.Add(('soap11_bindings={0}' -f $Soap11Bindings))
$Analysis.Add(('soap12_bindings={0}' -f $Soap12Bindings))
$Analysis.Add(('policy_nodes={0}' -f $PolicyCount))
$Analysis.Add(('faults={0}' -f ($Faults -join ',')))
$Analysis.Add('operations_and_actions:')
foreach ($Operation in $Operations) { $Analysis.Add(('  ' + $Operation)) }
$Analysis.Add('authentication=unknown_until_official_review')
Write-GatewayUtf8 (Join-Path $Snapshot 'protocol-analysis.txt') $Analysis.ToArray()

if ([IO.Directory]::Exists($Active)) {
    [IO.Directory]::Move($Active, (Join-Path $FisRoot ('active-previous-' + $Stamp)))
}
[IO.Directory]::Move($Snapshot, $Active)

Write-Host "[OK] Private snapshot импортирован: $Active"
Write-Host "SOAP 1.1 bindings: $Soap11Bindings; SOAP 1.2 bindings: $Soap12Bindings; operations: $($Operations.Count)"
Write-Host '[STOP-GATE] Authentication и официальный статус не подтверждены. SOAP-вызовы запрещены.'
