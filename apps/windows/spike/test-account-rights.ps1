<#
CI's test of account-rights.ps1 on a Windows runner, in Windows PowerShell 5.1 as the spike runs at a shop. A
task with a stored password, run as a new local account, signs that account in as a batch job; the spike's
first run on a real PC (18 November 2026) found Windows refuses it until the account holds "Log on as a batch
job". This makes such an account, shows whether its task runs without the right, then grants the right and
proves the task runs as that account, then revokes it. It removes everything it made.
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3
. (Join-Path $PSScriptRoot 'account-rights.ps1')

$name = 'HAQRightsTest'
$account = "$env:COMPUTERNAME\$name"
$taskName = 'HAQ Spike Rights Test'
$folder = Join-Path $env:ProgramData 'HAQRightsTest'
$proof = Join-Path $folder 'ran.txt'
$right = 'SeBatchLogonRight'

function Remove-TestAccount {
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false }
    if (Get-LocalUser -Name $name -ErrorAction SilentlyContinue) {
        try { Revoke-AccountRight $account $right } catch { Write-Host "Cleaning up the right: $_" }
        Remove-AccountProfile $account
        Remove-LocalUser -Name $name
    }
    if (Test-Path $folder) { Remove-Item $folder -Recurse -Force }
}

# Starts the task and says whether it ran as the account within a minute.
function Test-TaskRun {
    if (Test-Path $proof) { Remove-Item $proof }
    Start-ScheduledTask -TaskName $taskName
    $deadline = (Get-Date).AddSeconds(60)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 2
        if ((Test-Path $proof) -and (Get-Item $proof).Length -gt 0) { return $true }
    }
    return $false
}

function Get-Result { return '0x{0:X8}' -f (Get-ScheduledTaskInfo -TaskName $taskName).LastTaskResult }

Remove-TestAccount
New-Item -ItemType Directory -Path $folder | Out-Null
# Random, with each kind of character, as Windows Server's password rule asks.
$bytes = New-Object byte[] 18
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$password = 'Aa1#' + [Convert]::ToBase64String($bytes)
$secure = ConvertTo-SecureString $password -AsPlainText -Force
New-LocalUser -Name $name -Password $secure -PasswordNeverExpires -AccountNeverExpires -Description 'HAQ Retail OS CI: the batch sign-in right' | Out-Null
& icacls.exe $folder /grant "${account}:(OI)(CI)M" | Out-Null
$action = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/c whoami > `"$proof`""
Register-ScheduledTask -TaskName $taskName -Action $action -User $account -Password $password -Force | Out-Null
$password = $null

try {
    $held = @(Get-AccountRight $account) -contains $right
    $ran = Test-TaskRun
    Write-Host "Without the right: held $held, the task ran $ran, result $(Get-Result)"

    Grant-AccountRight $account $right
    if (@(Get-AccountRight $account) -notcontains $right) { throw "$account does not hold $right after the grant" }
    Grant-AccountRight $account $right
    if (@(Get-AccountRight $account) -notcontains $right) { throw "A second grant took $right away from $account" }
    if (-not (Test-TaskRun)) { throw "With the right the task still did not run: result $(Get-Result)" }
    $who = (Get-Content $proof -Raw).Trim()
    if ($who -ne $account) { throw "The task ran as $who, not $account" }
    Write-Host "With the right: the task ran as $who, result $(Get-Result)"

    Revoke-AccountRight $account $right
    if (@(Get-AccountRight $account) -contains $right) { throw "$account still holds $right after the revoke" }
    Revoke-AccountRight $account $right
    Write-Host 'Revoked, twice: the account holds no right of its own'
} finally {
    Remove-TestAccount
}
