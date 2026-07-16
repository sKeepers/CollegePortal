Set-StrictMode -Version 2

function Normalize-GatewayLocalPath {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [AllowEmptyString()]
        [object]$Path,

        [ValidateSet('Any', 'Directory', 'File')]
        [string]$ExpectedType = 'Any',

        [switch]$MustExist,

        [string]$ParameterName = 'Путь'
    )

    if ($null -eq $Path) { throw "$ParameterName не задан." }
    $Candidate = ([string]$Path).Trim()
    if ($Candidate.Length -eq 0) { throw "$ParameterName не может быть пустым." }
    if ([Text.RegularExpressions.Regex]::IsMatch($Candidate, '[\x00-\x1F]')) {
        throw "$ParameterName содержит управляющие символы."
    }
    if ([Management.Automation.WildcardPattern]::ContainsWildcardCharacters($Candidate)) {
        throw "$ParameterName содержит wildcard-символы."
    }
    if ($Candidate -match '^[A-Za-z][A-Za-z0-9+.-]*://') {
        throw "$ParameterName должен быть локальным путем, URI не поддерживается."
    }
    if ($Candidate.StartsWith('\\')) {
        throw "$ParameterName указывает UNC-путь. Установка с сетевого ресурса не поддерживается; скопируйте пакет на локальный диск."
    }
    if ($Candidate.IndexOfAny([IO.Path]::GetInvalidPathChars()) -ge 0 -or $Candidate -match '[<>|\"]') {
        throw "$ParameterName содержит недопустимые символы."
    }

    try {
        if (-not [IO.Path]::IsPathRooted($Candidate)) {
            $Location = Get-Location
            if ($Location.Provider.Name -ne 'FileSystem') {
                throw 'Текущий PowerShell provider не является файловой системой.'
            }
            $Candidate = [IO.Path]::Combine($Location.ProviderPath, $Candidate)
        }
        $FullPath = [IO.Path]::GetFullPath($Candidate)
    } catch {
        throw "$ParameterName имеет некорректный формат: $($_.Exception.Message)"
    }

    $Root = [IO.Path]::GetPathRoot($FullPath)
    while ($FullPath.Length -gt $Root.Length -and ($FullPath.EndsWith('\') -or $FullPath.EndsWith('/'))) {
        $FullPath = $FullPath.Substring(0, $FullPath.Length - 1)
    }

    if ($MustExist -and -not [IO.File]::Exists($FullPath) -and -not [IO.Directory]::Exists($FullPath)) {
        throw "$ParameterName не найден: $FullPath"
    }
    if ([IO.File]::Exists($FullPath) -and $ExpectedType -eq 'Directory') {
        throw "$ParameterName должен указывать каталог, но найден файл: $FullPath"
    }
    if ([IO.Directory]::Exists($FullPath) -and $ExpectedType -eq 'File') {
        throw "$ParameterName должен указывать файл, но найден каталог: $FullPath"
    }

    return $FullPath
}

function Get-CollegePortalSha256([string]$Path) {
    $Stream = [IO.File]::OpenRead($Path)
    $Sha = $null
    try {
        $Sha = [Security.Cryptography.SHA256]::Create()
        try { return ([BitConverter]::ToString($Sha.ComputeHash($Stream))).Replace('-', '').ToLowerInvariant() }
        finally { if ($null -ne $Sha) { $Sha.Clear() } }
    }
    finally { if ($null -ne $Stream) { $Stream.Close() } }
}

function Copy-GatewayStream([IO.Stream]$InputStream, [IO.Stream]$OutputStream) {
    $Buffer = New-Object byte[] 81920
    while (($Read = $InputStream.Read($Buffer, 0, $Buffer.Length)) -gt 0) {
        $OutputStream.Write($Buffer, 0, $Read)
    }
}

function Get-GatewayFileTail([string]$Path, [int]$LineCount) {
    if ($LineCount -lt 1) { return @() }
    $Queue = New-Object 'Collections.Generic.Queue[string]'
    $Reader = [IO.File]::OpenText($Path)
    try {
        while (($Line = $Reader.ReadLine()) -ne $null) {
            if ($Queue.Count -ge $LineCount) { $Queue.Dequeue() | Out-Null }
            $Queue.Enqueue($Line)
        }
    }
    finally { if ($null -ne $Reader) { $Reader.Close() } }
    return $Queue.ToArray()
}

function New-GatewayRandomBytes([int]$Length) {
    if ($Length -lt 1) { throw 'Длина random byte array должна быть больше нуля.' }
    $Bytes = New-Object byte[] $Length
    $Rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $Rng.GetBytes($Bytes) } finally { $Rng = $null }
    return ,$Bytes
}

function Read-GatewayConfig([string]$Path) {
    if (-not [IO.File]::Exists($Path)) { throw "Файл конфигурации не найден: $Path" }
    $Values = @{}
    $LineNumber = 0
    foreach ($RawLine in [IO.File]::ReadAllLines($Path)) {
        $LineNumber++
        $Line = $RawLine
        if ($LineNumber -eq 1 -and $Line.Length -gt 0 -and [int][char]$Line[0] -eq 0xFEFF) {
            $Line = $Line.Substring(1)
        }

        $Trimmed = $Line.Trim()
        if ($Trimmed.Length -eq 0 -or $Trimmed.StartsWith('#')) { continue }

        $SeparatorIndex = $Line.IndexOf('=')
        if ($SeparatorIndex -lt 0) {
            throw ('Некорректная строка конфигурации {0}: отсутствует разделитель ''=''.' -f $LineNumber)
        }

        $Key = $Line.Substring(0, $SeparatorIndex).Trim()
        if ($Key.Length -eq 0) {
            throw ('Некорректная строка конфигурации {0}: пустой ключ.' -f $LineNumber)
        }
        if ([Text.RegularExpressions.Regex]::IsMatch($Key, '[\x00-\x1F]')) {
            throw ('Некорректная строка конфигурации {0}: ключ содержит управляющие символы.' -f $LineNumber)
        }
        if ($Key -notmatch '^[A-Za-z][A-Za-z0-9_.-]*$') {
            throw ('Некорректная строка конфигурации {0}: недопустимое имя ключа ''{1}''.' -f $LineNumber, $Key)
        }
        if ($Values.ContainsKey($Key)) {
            throw ('Некорректная строка конфигурации {0}: ключ ''{1}'' задан повторно.' -f $LineNumber, $Key)
        }

        $Value = $Line.Substring($SeparatorIndex + 1)
        $Values[$Key] = $Value
    }
    return $Values
}

function Test-GatewayAdministrator {
    $Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
    return $Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}



function Get-GatewaySidAce([string]$Sid, [string]$Rights) {
    if ($Sid -notmatch '^S-1-5-(18|20|32-544)$') { throw "Unsupported well-known SID for Gateway ACL: $Sid" }
    if ($Rights -notmatch '^\([A-Z]+\)(\([A-Z]+\))*$') { throw "Invalid icacls rights expression: $Rights" }
    return ('*{0}:{1}' -f $Sid, $Rights)
}

function Get-GatewaySystemAce([string]$Rights) {
    return Get-GatewaySidAce 'S-1-5-18' $Rights
}

function Get-GatewayAdministratorsAce([string]$Rights) {
    return Get-GatewaySidAce 'S-1-5-32-544' $Rights
}

function Get-GatewayNetworkServiceAce([string]$Rights) {
    return Get-GatewaySidAce 'S-1-5-20' $Rights
}

function Get-GatewayUrlAclSddl() {
    # DACL grants generic execute to Network Service (NS) for HTTP.sys URL reservation.
    return 'D:(A;;GX;;;NS)'
}

function Get-GatewayServiceBinPath([string]$TargetExe, [string]$PrivateConfig) {
    foreach ($Path in @($TargetExe, $PrivateConfig)) {
        if ($Path -match '\s') {
            throw "Gateway service path contains whitespace and is not supported by Windows 7 sc.exe PowerShell invocation: $Path"
        }
    }
    return ('{0} --config {1}' -f $TargetExe, $PrivateConfig)
}
function Get-GatewayServiceCreateArguments([string]$ServiceName, [string]$BinPath) {
    return @(
        $ServiceName,
        'binPath=', $BinPath,
        'start=', 'auto',
        'obj=', 'NT AUTHORITY\NetworkService',
        'DisplayName=', 'CollegePortal Gateway'
    )
}

function Get-GatewayServiceConfigArguments([string]$ServiceName, [string]$BinPath) {
    return @(
        $ServiceName,
        'binPath=', $BinPath,
        'start=', 'auto',
        'obj=', 'NT AUTHORITY\NetworkService'
    )
}

function Get-GatewayServiceFailureArguments([string]$ServiceName) {
    return @(
        $ServiceName,
        'reset=', '86400',
        'actions=', 'restart/5000/restart/15000/none/0'
    )
}

function Get-GatewayHmacHeaders([string]$Method, [string]$Path, [byte[]]$Body, [string]$Secret) {
    if ($null -eq $Body) { $Body = New-Object byte[] 0 }
    $Timestamp = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    $Nonce = [Guid]::NewGuid().ToString('N')
    $RequestId = [Guid]::NewGuid().ToString('N')
    $Sha = [Security.Cryptography.SHA256]::Create()
    try { $BodyHash = ([BitConverter]::ToString($Sha.ComputeHash($Body))).Replace('-', '').ToLowerInvariant() }
    finally { if ($null -ne $Sha) { $Sha.Clear() } }
    $Canonical = $Method.ToUpperInvariant() + "`n" + $Path + "`n" + $Timestamp + "`n" + $Nonce + "`n" + $BodyHash
    $Hmac = New-Object Security.Cryptography.HMACSHA256(,[Text.Encoding]::UTF8.GetBytes($Secret))
    try { $Signature = [Convert]::ToBase64String($Hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($Canonical))) }
    finally { if ($null -ne $Hmac) { $Hmac.Clear() } }
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
        try { $RequestStream.Write($Body, 0, $Body.Length) } finally { $RequestStream.Close() }
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
        try { $Content = $Reader.ReadToEnd() } finally { $Reader.Close() }
        return New-Object PSObject -Property @{
            StatusCode = [int]$Response.StatusCode
            ContentType = [string]$Response.ContentType
            Content = $Content
            DurationMs = [int]([DateTime]::UtcNow - $Started).TotalMilliseconds
        }
    }
    finally { if ($null -ne $Response) { $Response.Close() } }
}

function Write-GatewayUtf8([string]$Path, [string[]]$Lines) {
    $Directory = [IO.Path]::GetDirectoryName($Path)
    if ($Directory -and -not [IO.Directory]::Exists($Directory)) { [IO.Directory]::CreateDirectory($Directory) | Out-Null }
    [IO.File]::WriteAllLines($Path, $Lines, (New-Object Text.UTF8Encoding($true)))
}
