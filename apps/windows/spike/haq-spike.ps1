<#
The Windows spike (D86): before the Windows setup is built, it proves on a Windows 10 or 11 machine that the
shop server can run hidden in WSL2. Throwaway: its sales are rehearsal records, so remove it afterwards and
never use it for a shop. README.md beside it has the steps and what to write down.

In an Administrator PowerShell, in this folder:

  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 wsl
  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 install -Ip 192.168.43.200 [-Tag 2026.11.3] [-Mode Hidden|Logon]
  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 check
  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 inside "haq check"
  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 remove

Mode Hidden (the default) runs the server as a hidden local account, HAQServer, from Windows' start, before
anyone signs in. Mode Logon is the fallback: it runs as you, when you sign in.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [ValidateSet('wsl', 'install', 'check', 'inside', 'remove')]
    [string] $Command,

    [Parameter(Position = 1)]
    [string] $Line = '',

    [string] $Ip = '',
    [string] $Tag = '2026.11.3',

    [ValidateSet('Hidden', 'Logon')]
    [string] $Mode = 'Hidden'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3
$ProgressPreference = 'SilentlyContinue'

$Root = Join-Path $env:ProgramData 'HAQSpike'
$Inside = '/mnt/' + $Root.Substring(0, 1).ToLower() + ($Root.Substring(2) -replace '\\', '/') + '/inside.sh'
$Distro = 'haq-server'
$Account = 'HAQServer'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootFsName = 'ubuntu-noble-wsl-amd64-24.04lts.rootfs.tar.gz'
$RootFsBase = 'https://cloud-images.ubuntu.com/wsl/releases/24.04/current'
$TaskRunner = 'HAQ Spike Runner'
$TaskServer = 'HAQ Spike Server'
$TaskBridge = 'HAQ Spike Bridge'
$RulePrefix = 'HAQ Spike'
$BatchRight = 'SeBatchLogonRight'

. (Join-Path $Here 'account-rights.ps1')

function Say([string] $Text) { Write-Host $Text }

function Assert-Admin {
    $me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run it in an Administrator PowerShell: right-click Start, then Terminal (Admin).'
    }
}

function Write-Utf8([string] $Path, [string] $Text) {
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding $false))
}

function Get-Setting([string] $Name) {
    $path = Join-Path $Root "$Name.txt"
    if (Test-Path $path) { return (Get-Content $path -Raw).Trim() }
    return ''
}

# Why a task that should run as the server's account did not: Windows logs each sign-in it refuses.
function Get-RefusedSignIn([datetime] $Since) {
    $reasons = @{
        '0xc000015b' = 'it may not sign in as a batch job, which a scheduled task needs'
        '0xc000006a' = 'its password is wrong'
        '0xc0000072' = 'it is disabled'
        '0xc0000234' = 'it is locked out'
    }
    $events = @(Get-WinEvent -FilterHashtable @{ LogName = 'Security'; Id = 4625; StartTime = $Since } -MaxEvents 50 -ErrorAction SilentlyContinue)
    foreach ($e in $events) {
        $data = @{}
        foreach ($d in ([xml]$e.ToXml()).Event.EventData.Data) { $data[$d.GetAttribute('Name')] = $d.InnerText }
        if ($data['TargetUserName'] -eq $Account) {
            $status = "$($data['Status'])".ToLower()
            $why = $reasons[$status]
            if (-not $why) { $why = "status $status" }
            return "Windows refused $Account's sign-in: $why."
        }
    }
    return ''
}

# Runs PowerShell as the account that owns the distribution (WSL belongs to a Windows user), through the
# runner task, and shows its output as it comes. Only Administrators can write the queue it runs.
function Invoke-AsServer([string] $Script, [int] $Minutes = 30) {
    $queue = Join-Path $Root 'queue.ps1'
    $done = Join-Path $Root 'logs\queue.done'
    $log = Join-Path $Root 'logs\runner.log'
    if (Test-Path $done) { Remove-Item $done }
    $offset = 0
    if (Test-Path $log) { $offset = (Get-Item $log).Length }
    Write-Utf8 $queue ("`$env:WSL_UTF8 = '1'`r`n" + $Script + "`r`nexit `$LASTEXITCODE`r`n")
    $began = Get-Date
    Start-ScheduledTask -TaskName $TaskRunner
    $deadline = $began.AddMinutes($Minutes)
    while ($true) {
        Start-Sleep -Seconds 2
        if (Test-Path $log) {
            $stream = [IO.File]::Open($log, 'Open', 'Read', 'ReadWrite')
            try {
                if ($stream.Length -gt $offset) {
                    $null = $stream.Seek($offset, 'Begin')
                    $reader = New-Object IO.StreamReader($stream)
                    $text = $reader.ReadToEnd()
                    $offset = $stream.Length
                    if ($text.Trim() -ne '') { Write-Host $text.TrimEnd() }
                }
            } finally {
                $stream.Dispose()
            }
        }
        if (Test-Path $done) { break }
        # A task that never starts leaves no log and no end: say why after a minute, not at the deadline.
        if (((Get-Date) - $began).TotalSeconds -gt 60 -and "$((Get-ScheduledTask -TaskName $TaskRunner).State)" -ne 'Running') {
            Start-Sleep -Seconds 3
            if (Test-Path $done) { break }
            $result = (Get-ScheduledTaskInfo -TaskName $TaskRunner).LastTaskResult
            throw ("The runner task is not running and did not finish (Windows' result 0x{0:X8}). {1}" -f $result, (Get-RefusedSignIn $began.AddSeconds(-5))).Trim()
        }
        if ((Get-Date) -gt $deadline) { throw "No end within $Minutes minutes; the log is $log." }
    }
    $code = "$(Get-Content $done -Raw)".Trim()
    if ($code -ne '0' -and $code -ne '') { throw "It stopped with code $code; the output above says why." }
}

function Register-SpikeTask([string] $Name, [string] $Execute, [string] $Arguments, $Trigger, [switch] $AsSystem, [string] $Password = '') {
    $action = New-ScheduledTaskAction -Execute $Execute -Argument $Arguments -WorkingDirectory $Root
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $task = @{ TaskName = $Name; Action = $action; Settings = $settings; Force = $true }
    if ($null -ne $Trigger) { $task.Trigger = $Trigger }
    if ($AsSystem) {
        $task.Principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    } elseif ((Get-Setting 'mode') -eq 'Hidden') {
        $task.User = "$env:COMPUTERNAME\$Account"
        $task.Password = $Password
    } else {
        $task.Principal = New-ScheduledTaskPrincipal -UserId (Get-Setting 'owner') -LogonType Interactive -RunLevel Highest
    }
    Register-ScheduledTask @task | Out-Null
}

function New-Password {
    $chars = [char[]]'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789#%+=@'
    $bytes = New-Object byte[] 28
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Install-Spike {
    Assert-Admin
    if ($Ip -eq '') { throw 'Give this PC''s fixed address: -Ip 192.168.43.200' }
    if (-not (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -eq $Ip })) {
        throw "This PC does not have the address $Ip. Give it that fixed address first (README.md, step 1)."
    }
    & wsl.exe --version *> $null
    if ($LASTEXITCODE -ne 0) { throw 'WSL is not installed yet: run the wsl command first, then restart.' }

    Say 'Making the spike''s folder...'
    foreach ($folder in @('', 'logs', 'status', 'secrets', 'receipts', 'wsl')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $Root $folder) | Out-Null
    }
    Write-Utf8 (Join-Path $Root 'mode.txt') $Mode
    Write-Utf8 (Join-Path $Root 'ip.txt') $Ip
    $password = ''
    if ($Mode -eq 'Hidden') {
        $password = New-Password
        $secure = ConvertTo-SecureString $password -AsPlainText -Force
        if (Get-LocalUser -Name $Account -ErrorAction SilentlyContinue) {
            Set-LocalUser -Name $Account -Password $secure
        } else {
            New-LocalUser -Name $Account -Password $secure -PasswordNeverExpires -UserMayNotChangePassword -AccountNeverExpires `
                -Description 'HAQ Retail OS spike: runs the hidden shop server' | Out-Null
        }
        # Hidden from the sign-in screen; nobody signs in as it.
        $list = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList'
        New-Item -Path $list -Force | Out-Null
        New-ItemProperty -Path $list -Name $Account -Value 0 -PropertyType DWord -Force | Out-Null
        $owner = "$env:COMPUTERNAME\$Account"
        # Its tasks sign it in as a batch job, which Windows refuses (0xC000015B) to an account without this
        # right; Task Scheduler does not grant it by itself. It is the only right the account is given.
        Grant-AccountRight $owner $BatchRight
    } else {
        $owner = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    }
    Write-Utf8 (Join-Path $Root 'owner.txt') $owner
    # Administrators and Windows itself own the folder; the server's account reads it, and writes only its logs,
    # its status, the distribution and the token it deletes after use.
    & icacls.exe $Root /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' "${owner}:(OI)(CI)RX" | Out-Null
    foreach ($folder in @('logs', 'status', 'secrets', 'wsl')) {
        & icacls.exe (Join-Path $Root $folder) /grant "${owner}:(OI)(CI)M" | Out-Null
    }

    $inside = (Get-Content (Join-Path $Here 'inside.sh') -Raw) -replace "`r`n", "`n"
    Write-Utf8 (Join-Path $Root 'inside.sh') $inside
    Copy-Item (Join-Path $Here 'bridge.ps1') (Join-Path $Root 'bridge.ps1') -Force
    Write-Utf8 (Join-Path $Root 'runner.ps1') @'
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$log = Join-Path $root 'logs\runner.log'
$code = 1
try {
    & (Join-Path $root 'queue.ps1') 2>&1 | ForEach-Object { "$_" } | Out-File -FilePath $log -Append -Encoding utf8
    $code = $LASTEXITCODE
    if ($null -eq $code) { $code = 0 }
} catch {
    "$_" | Out-File -FilePath $log -Append -Encoding utf8
}
[IO.File]::WriteAllText((Join-Path $root 'logs\queue.done'), "$code")
'@

    # Both counters print to the stand-in bridge on this PC, as a USB printer's bridge will be reached.
    $printer = @{ host = $Ip; port = 9100 }
    $shopProfile = [ordered]@{
        schema_version = 1
        shop = @{ name = 'HAQ Spike' }
        branch = [ordered]@{ code = 'B01'; name = 'Main branch' }
        terminals = @(
            [ordered]@{ code = 'T01'; name = 'Counter 1'; drawer = $true; kind = 'counter'; printer = $printer },
            [ordered]@{ code = 'T02'; name = 'Counter 2'; drawer = $false; kind = 'counter'; printer = $printer },
            [ordered]@{ code = 'D01'; name = 'Owner''s phone'; drawer = $false; kind = 'back_office' }
        )
        receipt = [ordered]@{ shop_name = 'HAQ Spike'; address = ''; phone = ''; footer = 'A rehearsal receipt.' }
        settings = @{}
    }
    Write-Utf8 (Join-Path $Root 'profile.json') ($shopProfile | ConvertTo-Json -Depth 6)

    Say 'The images are private: give the read-only GHCR token (a classic token with only read:packages).'
    $user = Read-Host 'GitHub user name for the token'
    $token = Read-Host 'The token (it is not shown)' -AsSecureString
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($token))
    Write-Utf8 (Join-Path $Root 'secrets\ghcr_user') $user
    Write-Utf8 (Join-Path $Root 'secrets\ghcr_token') $plain
    $plain = $null

    $gz = Join-Path $Root $RootFsName
    $tar = Join-Path $Root 'rootfs.tar'
    if (-not (Test-Path $tar)) {
        Say 'Downloading Ubuntu 24.04 for WSL (about 360 MB)...'
        & curl.exe --fail --location --silent --show-error --output $gz "$RootFsBase/$RootFsName"
        if ($LASTEXITCODE -ne 0) { throw 'The download failed; check the internet connection.' }
        $sums = & curl.exe --fail --location --silent --show-error "$RootFsBase/SHA256SUMS"
        $want = (($sums | Where-Object { $_ -match [regex]::Escape($RootFsName) + '$' }) -split '\s+')[0]
        $got = (Get-FileHash $gz -Algorithm SHA256).Hash.ToLower()
        if ($want -ne $got) { throw "The download does not match Ubuntu's checksum ($got, not $want)." }
        $in = [IO.File]::OpenRead($gz)
        $out = [IO.File]::Create($tar)
        $zip = New-Object IO.Compression.GZipStream($in, [IO.Compression.CompressionMode]::Decompress)
        try { $zip.CopyTo($out) } finally { $zip.Dispose(); $out.Dispose(); $in.Dispose() }
        Remove-Item $gz
        & icacls.exe $tar /grant "${owner}:R" | Out-Null
    }

    Say 'Registering the tasks...'
    $powershell = 'powershell.exe'
    $hidden = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden'
    Register-SpikeTask -Name $TaskRunner -Execute $powershell -Arguments "$hidden -File `"$Root\runner.ps1`"" -Trigger $null -Password $password
    if ($Mode -eq 'Hidden') { $start = New-ScheduledTaskTrigger -AtStartup } else { $start = New-ScheduledTaskTrigger -AtLogOn -User $owner }
    $keepalive = "/c wsl.exe -d $Distro -u root -- bash $Inside keepalive >> `"$Root\logs\keepalive.log`" 2>&1"
    Register-SpikeTask -Name $TaskServer -Execute 'cmd.exe' -Arguments $keepalive -Trigger $start -Password $password
    Register-SpikeTask -Name $TaskBridge -Execute $powershell -Arguments "$hidden -File `"$Root\bridge.ps1`"" -Trigger (New-ScheduledTaskTrigger -AtStartup) -AsSystem
    $password = $null

    Say 'Importing the distribution as its owner...'
    Invoke-AsServer "wsl.exe --import $Distro `"$Root\wsl`" `"$tar`" --version 2"
    Invoke-AsServer "wsl.exe -d $Distro -u root -- bash $Inside prepare; wsl.exe --terminate $Distro"
    Start-ScheduledTask -TaskName $TaskServer
    Start-Sleep -Seconds 20

    Say "Installing release $Tag inside it (10 to 30 minutes)..."
    Invoke-AsServer "wsl.exe -d $Distro -u root -- bash $Inside install $Ip $Tag" -Minutes 90

    Say 'Forwarding the server''s ports and opening the firewall...'
    foreach ($port in 80, 443) {
        & netsh.exe interface portproxy delete v4tov4 listenaddress=$Ip listenport=$port 2>$null | Out-Null
        & netsh.exe interface portproxy add v4tov4 listenaddress=$Ip listenport=$port connectaddress=127.0.0.1 connectport=$port | Out-Null
    }
    Get-NetFirewallRule -DisplayName "$RulePrefix*" -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    New-NetFirewallRule -DisplayName "$RulePrefix`: the shop server" -Direction Inbound -Protocol TCP -LocalPort 80, 443 -Action Allow -Profile Any | Out-Null
    New-NetFirewallRule -DisplayName "$RulePrefix`: the print bridge" -Direction Inbound -Protocol TCP -LocalPort 9100 -Action Allow -Profile Any | Out-Null
    Start-ScheduledTask -TaskName $TaskBridge

    Say ''
    Say "Installed. The server answers at https://$Ip. Next (README.md has each step):"
    Say '  1. Check it:                 powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 check'
    Say '  2. A code for this counter:  powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 inside "haq enrol T01"'
    Say "  3. In Chrome, open http://$Ip/setup, trust the certificate with its one line, and type the code."
}

function Show-Check {
    Assert-Admin
    $ip = Get-Setting 'ip'
    $lines = New-Object Collections.Generic.List[string]
    $add = { param($text) $lines.Add($text); Write-Host $text }

    $os = Get-CimInstance Win32_OperatingSystem
    $cs = Get-CimInstance Win32_ComputerSystem
    $cores = (Get-CimInstance Win32_Processor | Measure-Object -Property NumberOfCores -Sum).Sum
    & $add "Report:       $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz')"
    & $add "Windows:      $($os.Caption), version $($os.Version)"
    & $add ('PC:           {0} cores, {1:N1} GB memory, hypervisor {2}' -f $cores, ($cs.TotalPhysicalMemory / 1GB), $cs.HypervisorPresent)
    $env:WSL_UTF8 = '1'
    $wsl = (& wsl.exe --version 2>$null | Select-Object -First 2) -join '; '
    & $add "WSL:          $wsl"
    & $add "Mode:         $(Get-Setting 'mode'), run as $(Get-Setting 'owner')"
    if ((Get-Setting 'mode') -eq 'Hidden' -and (Get-LocalUser -Name $Account -ErrorAction SilentlyContinue)) {
        $rights = @(Get-AccountRight "$env:COMPUTERNAME\$Account")
        if ($rights.Count -eq 0) { $rights = @('none') }
        & $add "Its rights:   $($rights -join ', ')"
    }
    & $add "Windows on:   $($os.LastBootUpTime.ToString('yyyy-MM-dd HH:mm:ss'))"
    $sessions = Get-CimInstance Win32_LogonSession | Where-Object { $_.LogonType -in 2, 10, 11 }
    foreach ($session in $sessions) {
        $who = Get-CimAssociatedInstance -InputObject $session -ResultClassName Win32_Account -ErrorAction SilentlyContinue
        if ($who) { & $add "Signed in:    $($who.Name) at $($session.StartTime.ToString('yyyy-MM-dd HH:mm:ss'))" }
    }
    foreach ($name in @('started', 'ready')) {
        $path = Join-Path $Root "status\$name.txt"
        if (Test-Path $path) {
            $at = [DateTime]::Parse((Get-Content $path -Raw).Trim()).ToLocalTime()
            & $add ("Server {0,-7} {1} ({2:N1} minutes after Windows started)" -f "${name}:", $at.ToString('yyyy-MM-dd HH:mm:ss'), ($at - $os.LastBootUpTime).TotalMinutes)
        }
    }
    foreach ($name in @($TaskServer, $TaskBridge, $TaskRunner)) {
        $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($task) {
            $info = Get-ScheduledTaskInfo -TaskName $name
            & $add ("Task:         {0}: {1}, last run {2}, result {3}" -f $name, $task.State, $info.LastRunTime, $info.LastTaskResult)
        } else {
            & $add "Task:         $name is missing"
        }
    }
    if ($ip -ne '') {
        $up = & curl.exe -sk -o NUL -m 10 -w '%{http_code} in %{time_total} s' "https://$ip/up"
        & $add "From this PC: https://$ip/up answered $up"
    }
    $memory = (Get-Process -Name vmmem, vmmemWSL -ErrorAction SilentlyContinue | Measure-Object -Property WorkingSet64 -Sum).Sum
    & $add ('WSL memory:   {0:N1} GB in use' -f ($memory / 1GB))
    $drive = Get-PSDrive -Name $Root.Substring(0, 1)
    & $add ('Disk free:    {0:N1} GB' -f ($drive.Free / 1GB))
    $receipts = @(Get-ChildItem (Join-Path $Root 'receipts') -Filter *.bin -ErrorAction SilentlyContinue)
    $last = ''
    if ($receipts.Count -gt 0) { $last = ', the last at ' + ($receipts | Sort-Object LastWriteTime | Select-Object -Last 1).LastWriteTime.ToString('HH:mm:ss') }
    & $add "Bridge:       $($receipts.Count) receipts received$last"
    $status = Join-Path $Root 'status\status.txt'
    & $add '--- inside the distribution (written every 30 seconds) ---'
    if (Test-Path $status) { foreach ($l in Get-Content $status) { & $add $l } } else { & $add 'No status yet: the server task has not written one.' }
    & $add '--- the server task''s last lines ---'
    $keepalive = Join-Path $Root 'logs\keepalive.log'
    if (Test-Path $keepalive) { foreach ($l in Get-Content $keepalive -Tail 5) { & $add $l } }

    $report = Join-Path $Root ("report-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')
    Write-Utf8 $report ($lines -join "`r`n")
    Say ''
    Say "Kept as $report, to paste back."
}

function Remove-Spike {
    Assert-Admin
    Say 'Stopping and removing the spike...'
    foreach ($name in @($TaskServer, $TaskBridge)) {
        if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
            Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
        }
    }
    if (Get-ScheduledTask -TaskName $TaskRunner -ErrorAction SilentlyContinue) {
        try { Invoke-AsServer "wsl.exe --terminate $Distro; wsl.exe --unregister $Distro" -Minutes 5 } catch { Say "The distribution: $_" }
        Unregister-ScheduledTask -TaskName $TaskRunner -Confirm:$false
    }
    $ip = Get-Setting 'ip'
    if ($ip -ne '') {
        foreach ($port in 80, 443) { & netsh.exe interface portproxy delete v4tov4 listenaddress=$ip listenport=$port 2>$null | Out-Null }
    }
    Get-NetFirewallRule -DisplayName "$RulePrefix*" -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Subject -like '*HAQ Retail OS shop authority*' } | Remove-Item
    if (Get-LocalUser -Name $Account -ErrorAction SilentlyContinue) {
        try { Revoke-AccountRight "$env:COMPUTERNAME\$Account" $BatchRight } catch { Say "The account's right: $_" }
        Get-CimInstance Win32_UserProfile | Where-Object { $_.LocalPath -like "*\$Account" } | Remove-CimInstance
        Remove-LocalUser -Name $Account
    }
    Remove-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList' -Name $Account -ErrorAction SilentlyContinue
    if (Test-Path $Root) { Remove-Item $Root -Recurse -Force }
    Say 'Removed. WSL itself stays installed; remove it in Settings, Apps, if it is not wanted.'
}

switch ($Command) {
    'wsl' {
        Assert-Admin
        Say 'Installing WSL, with no distribution of its own...'
        & wsl.exe --install --no-distribution
        Say 'Restart Windows now, then run the install command.'
    }
    'install' { Install-Spike }
    'check' { Show-Check }
    'inside' {
        Assert-Admin
        if ($Line -eq '') { throw 'Give the command to run inside, in quotes: inside "haq check"' }
        Invoke-AsServer ("wsl.exe -d $Distro -u root -- bash $Inside run '" + $Line.Replace("'", "''") + "'") -Minutes 120
    }
    'remove' { Remove-Spike }
}
