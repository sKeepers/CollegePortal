Set-StrictMode -Version 2

function Get-CollegePortalSha256([string]$Path) {
    $Stream = [IO.File]::OpenRead($Path)
    try {
        $Sha = [Security.Cryptography.SHA256]::Create()
        try { return ([BitConverter]::ToString($Sha.ComputeHash($Stream))).Replace('-', '').ToLowerInvariant() }
        finally { $Sha.Dispose() }
    }
    finally { $Stream.Dispose() }
}

function Read-GatewayConfig([string]$Path) {
    if (-not [IO.File]::Exists($Path)) { throw "Файл конфигурации не найден: $Path" }
    $Values = @{}
    foreach ($RawLine in [IO.File]::ReadAllLines($Path)) {
        $Line = $RawLine.Trim()
        if ($Line.Length -eq 0 -or $Line.StartsWith('#') -or -not $Line.Contains('=')) { continue }
        $Parts = $Line.Split(@('='), 2)
        $Values[$Parts[0].Trim()] = $Parts[1].Trim()
    }
    return $Values
}

function Test-GatewayAdministrator {
    $Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
    return $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-GatewayHmacHeaders([string]$Method, [string]$Path, [byte[]]$Body, [string]$Secret) {
    if ($null -eq $Body) { $Body = New-Object byte[] 0 }
    $Timestamp = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    $Nonce = [Guid]::NewGuid().ToString('N')
    $RequestId = [Guid]::NewGuid().ToString('N')
    $Sha = [Security.Cryptography.SHA256]::Create()
    try { $BodyHash = ([BitConverter]::ToString($Sha.ComputeHash($Body))).Replace('-', '').ToLowerInvariant() }
    finally { $Sha.Dispose() }
    $Canonical = $Method.ToUpperInvariant() + "`n" + $Path + "`n" + $Timestamp + "`n" + $Nonce + "`n" + $BodyHash
    $Hmac = New-Object Security.Cryptography.HMACSHA256(,[Text.Encoding]::UTF8.GetBytes($Secret))
    try { $Signature = [Convert]::ToBase64String($Hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($Canonical))) }
    finally { $Hmac.Dispose() }
    return @{
        'X-Gateway-Timestamp' = $Timestamp
        'X-Gateway-Nonce' = $Nonce
        'X-Gateway-Request-Id' = $RequestId
        'X-Gateway-Body-SHA256' = $BodyHash
        'X-Gateway-Signature' = $Signature
    }
}

function Invoke-GatewayHttp([string]$Uri, [string]$Method, [hashtable]$Headers, [byte[]]$Body, [int]$TimeoutSeconds) {
    if ($null -eq $Headers) { $Headers = @{} }
    if ($null -eq $Body) { $Body = New-Object byte[] 0 }
    $Request = [Net.HttpWebRequest]::Create($Uri)
    $Request.Method = $Method
    $Request.Timeout = $TimeoutSeconds * 1000
    $Request.ReadWriteTimeout = $TimeoutSeconds * 1000
    $Request.AllowAutoRedirect = $false
    foreach ($Name in $Headers.Keys) { $Request.Headers[$Name] = [string]$Headers[$Name] }
    if ($Body.Length -gt 0) {
        $Request.ContentType = 'application/json; charset=utf-8'
        $Request.ContentLength = $Body.Length
        $RequestStream = $Request.GetRequestStream()
        try { $RequestStream.Write($Body, 0, $Body.Length) } finally { $RequestStream.Dispose() }
    }

    $Started = [DateTime]::UtcNow
    $Response = $null
    try {
        try { $Response = [Net.HttpWebResponse]$Request.GetResponse() }
        catch [Net.WebException] {
            if ($null -eq $_.Exception.Response) { throw }
            $Response = [Net.HttpWebResponse]$_.Exception.Response
        }
        $Reader = New-Object IO.StreamReader($Response.GetResponseStream(), [Text.Encoding]::UTF8)
        try { $Content = $Reader.ReadToEnd() } finally { $Reader.Dispose() }
        return New-Object PSObject -Property @{
            StatusCode = [int]$Response.StatusCode
            ContentType = [string]$Response.ContentType
            Content = $Content
            DurationMs = [int]([DateTime]::UtcNow - $Started).TotalMilliseconds
        }
    }
    finally { if ($null -ne $Response) { $Response.Dispose() } }
}

function Write-GatewayUtf8([string]$Path, [string[]]$Lines) {
    $Directory = [IO.Path]::GetDirectoryName($Path)
    if ($Directory -and -not [IO.Directory]::Exists($Directory)) { [IO.Directory]::CreateDirectory($Directory) | Out-Null }
    [IO.File]::WriteAllLines($Path, $Lines, (New-Object Text.UTF8Encoding($true)))
}
