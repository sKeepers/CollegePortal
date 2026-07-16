param(
    [string]$HostAlias = "college-vipnet",
    [string]$IdentityFile = "$env:USERPROFILE\.ssh\id_ed25519",
    [string]$PublicKeyFile = "$env:USERPROFILE\.ssh\id_ed25519.pub"
)

$ErrorActionPreference = "Stop"

function Invoke-Checked {
    param(
        [string]$Label,
        [string[]]$Command
    )

    Write-Host "== $Label =="
    & $Command[0] $Command[1..($Command.Length - 1)]
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE"
    }
}

function Invoke-Remote {
    param(
        [string]$Label,
        [string]$RemoteCommand
    )

    Invoke-Checked $Label @(
        "ssh",
        "-o", "BatchMode=yes",
        "-o", "PreferredAuthentications=publickey",
        "-o", "PasswordAuthentication=no",
        "-o", "IdentitiesOnly=yes",
        $HostAlias,
        $RemoteCommand
    )
}

if (-not (Test-Path -LiteralPath $IdentityFile)) {
    throw "Private key file is not accessible for ssh. Do not copy it into the repository."
}

if (-not (Test-Path -LiteralPath $PublicKeyFile)) {
    throw "Public key file not found: $PublicKeyFile"
}

Write-Host "CollegePortal ViPNet SSH test"
Write-Host "Host alias: $HostAlias"
Write-Host "Private key: present (content is not printed)"

Invoke-Checked "local public key fingerprint" @("ssh-keygen", "-lf", $PublicKeyFile)
Invoke-Remote "remote hostname" "hostname"
Invoke-Remote "remote user" "whoami"
Invoke-Remote "sshd config validation" "C:\Tools\OpenSSH-Win64\sshd.exe -t & echo SSHD_T_EXIT_%ERRORLEVEL%"
Invoke-Remote "authorized keys exists" "if exist C:\ProgramData\ssh\administrators_authorized_keys echo AK_EXISTS"
Invoke-Remote "authorized keys ACL" "icacls C:\ProgramData\ssh\administrators_authorized_keys"

$localTest = Join-Path $env:TEMP ("collegeportal-vipnet-scp-" + [guid]::NewGuid().ToString("N") + ".txt")
$remoteTest = "C:/Windows/Temp/collegeportal_codex_ssh_test.txt"
Set-Content -LiteralPath $localTest -Value "collegeportal ssh scp test" -Encoding ASCII
try {
    Invoke-Checked "scp upload test" @("scp", "-q", "-o", "BatchMode=yes", $localTest, "$HostAlias`:$remoteTest")
    Invoke-Remote "scp remote readback" "type C:\Windows\Temp\collegeportal_codex_ssh_test.txt"
    Invoke-Remote "scp remote cleanup" "del C:\Windows\Temp\collegeportal_codex_ssh_test.txt"
}
finally {
    Remove-Item -LiteralPath $localTest -Force -ErrorAction SilentlyContinue
}

Write-Host "SSH/SCP test completed."
