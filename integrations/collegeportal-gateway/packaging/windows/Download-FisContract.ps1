[CmdletBinding()]
param([string]$InstallRoot = 'C:\CollegePortalGateway')

$ErrorActionPreference = 'Stop'
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptRoot 'Gateway-Common.ps1')

$BaseUri = 'http://10.0.3.1:8383/api/import/ImportService.svc'
$FisRoot = Join-Path $InstallRoot 'specs\fis'
$Stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$Staging = Join-Path $FisRoot ('.download-' + $Stamp)
$Discovered = Join-Path $FisRoot 'discovered'
$ManifestLines = New-Object Collections.Generic.List[string]

$Files = @(
    @('import-service.single.wsdl', '?singleWsdl'),
    @('import-service.wsdl.xml', '?wsdl'),
    @('import-service-wrapper.xsd', '?xsd=xsd0'),
    @('microsoft-serialization.xsd', '?xsd=xsd1'),
    @('import-service.disco.xml', '?disco')
)

function Assert-Xml([string]$Path, [string]$ExpectedRoot) {
    $Settings = New-Object Xml.XmlReaderSettings
    $Settings.DtdProcessing = [Xml.DtdProcessing]::Prohibit
    $Settings.XmlResolver = $null
    $Reader = [Xml.XmlReader]::Create($Path, $Settings)
    try {
        while ($Reader.Read()) {
            if ($Reader.NodeType -eq [Xml.XmlNodeType]::Element) {
                if ($Reader.LocalName -ne $ExpectedRoot) { throw "Неожиданный XML root '$($Reader.LocalName)' в $Path; ожидается '$ExpectedRoot'." }
                return
            }
        }
        throw "XML не содержит корневого элемента: $Path"
    }
    finally { $Reader.Dispose() }
}

if (-not [IO.Directory]::Exists($Staging)) { [IO.Directory]::CreateDirectory($Staging) | Out-Null }
$ManifestLines.Add("downloaded_at_utc`tname`thttp_status`tcontent_type`tsize_bytes`tsha256`turl")

try {
    foreach ($Entry in $Files) {
        $Name = $Entry[0]
        $Uri = $BaseUri + $Entry[1]
        $Target = Join-Path $Staging $Name
        Write-Host "Запрос TEST: $Uri"
        $Request = [Net.HttpWebRequest]::Create($Uri)
        $Request.Method = 'GET'
        $Request.AllowAutoRedirect = $false
        $Request.Timeout = 15000
        $Request.ReadWriteTimeout = 15000
        $Response = [Net.HttpWebResponse]$Request.GetResponse()
        try {
            if ([int]$Response.StatusCode -ne 200) { throw "HTTP $([int]$Response.StatusCode) для $Name" }
            $Output = [IO.File]::Create($Target)
            try { $Response.GetResponseStream().CopyTo($Output) } finally { $Output.Dispose() }
            $ExpectedRoot = if ($Name -like '*.xsd') { 'schema' } elseif ($Name -like '*.disco*') { 'discovery' } else { 'definitions' }
            Assert-Xml $Target $ExpectedRoot
            $Size = (New-Object IO.FileInfo($Target)).Length
            if ($Size -le 0) { throw "Получен пустой файл: $Name" }
            $Hash = Get-CollegePortalSha256 $Target
            $ManifestLines.Add(("{0}`t{1}`t{2}`t{3}`t{4}`t{5}`t{6}" -f
                [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ'), $Name, [int]$Response.StatusCode,
                ([string]$Response.ContentType).Replace("`t", ' '), $Size, $Hash, $Uri))
            Write-Host "[OK] $Name, $Size bytes, SHA-256=$Hash"
        }
        finally { $Response.Dispose() }
    }

    Write-GatewayUtf8 (Join-Path $Staging 'download-manifest.tsv') $ManifestLines.ToArray()
    if ([IO.Directory]::Exists($Discovered)) {
        $Archive = Join-Path $FisRoot ('discovered-previous-' + $Stamp)
        [IO.Directory]::Move($Discovered, $Archive)
    }
    [IO.Directory]::Move($Staging, $Discovered)
    Write-Host "[OK] Контракт TEST сохранен: $Discovered"
    Write-Host '[STOP-GATE] Файлы еще не одобрены и не разрешают SOAP-вызовы.'
}
catch {
    Write-Host ('[ОШИБКА] ' + $_.Exception.Message)
    Write-Host "Неполные private-данные оставлены для диагностики: $Staging"
    Write-Host '[STOP-GATE] Импорт контракта и SOAP запрещены.'
    exit 1
}
