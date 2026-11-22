<#
The setup program's own test (D86, D137): it installs a counter silently against a stand-in shop server on
127.0.0.2, runs again as an update, and is uninstalled, keeping the shop's folder. CI runs it on every pull
request with the commit's build, and windows-release.yml runs it on a release's own setup before keeping it.
It changes the PC it runs on, so it runs only on a throwaway Windows runner, never on a shop's PC.

  pwsh -NoProfile -File apps/windows/setup/test-setup.ps1 -Setup <HAQ-Retail-OS-Setup-....exe>
#>
param([Parameter(Mandatory = $true)] [string] $Setup)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path $Setup -PathType Leaf)) { throw "There is no setup at $Setup" }

$site = Join-Path $env:RUNNER_TEMP 'shop-site'
New-Item -ItemType Directory -Force $site | Out-Null
$ca = New-SelfSignedCertificate -Subject 'CN=HAQ Retail OS shop authority' -CertStoreLocation Cert:\CurrentUser\My `
  -KeyUsage CertSign, CRLSign -TextExtension @('2.5.29.19={critical}{text}ca=1')
$pem = "-----BEGIN CERTIFICATE-----`n" + [Convert]::ToBase64String($ca.RawData, 'InsertLineBreaks') + "`n-----END CERTIFICATE-----`n"
[IO.File]::WriteAllText((Join-Path $site 'ca.crt'), $pem)
[IO.File]::WriteAllText((Join-Path $site 'ca.thumbprint'), (($ca.Thumbprint -split '(.{4})' | Where-Object { $_ }) -join ' ') + "`n")
Remove-Item "Cert:\CurrentUser\My\$($ca.Thumbprint)"
$shop = Start-Job -ScriptBlock {
  $listener = [Net.HttpListener]::new(); $listener.Prefixes.Add('http://127.0.0.2:80/'); $listener.Start()
  while ($listener.IsListening) {
    $context = $listener.GetContext()
    $file = Join-Path $using:site $context.Request.Url.AbsolutePath.TrimStart('/')
    if (Test-Path $file -PathType Leaf) { $body = [IO.File]::ReadAllBytes($file) } else { $body = [byte[]]@(); $context.Response.StatusCode = 404 }
    $context.Response.OutputStream.Write($body, 0, $body.Length); $context.Response.Close()
  }
}
$deadline = (Get-Date).AddSeconds(30)
while ($true) {
  try { Invoke-WebRequest http://127.0.0.2/ca.thumbprint -UseBasicParsing | Out-Null; break }
  catch { if ((Get-Date) -gt $deadline) { Receive-Job $shop; throw 'the stand-in server never answered' }; Start-Sleep 1 }
}

$answers = Join-Path $env:RUNNER_TEMP 'answers.json'
$receipts = Join-Path $env:RUNNER_TEMP 'setup-receipts.bin'
# The thumbprint's first 16 characters, as `haq enrol` shows them on the server (D125).
@{ mode = 'counter'; address = '127.0.0.1'; server = '127.0.0.2'; counter = 'T02'; code = '4827-1093'; thumbprint = $ca.Thumbprint.Substring(0, 16); printer = "file:$receipts" } |
  ConvertTo-Json | Set-Content $answers -Encoding ascii
$app = Join-Path $env:ProgramFiles 'HAQ Retail OS'
# Waits for the setup itself, not for whatever it started, at most 5 minutes; the logs say how far it got.
function Invoke-Setup([string] $log, [string[]] $more) {
  $p = Start-Process $Setup -ArgumentList (@('/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', "/LOG=$log") + $more) -PassThru
  $finished = $p.WaitForExit(300000)
  Get-Content $log -Tail 25 -ErrorAction SilentlyContinue | Write-Host
  Get-Content C:\ProgramData\HAQ\logs\setup.log -Tail 25 -ErrorAction SilentlyContinue | Write-Host
  if (-not $finished) {
    Get-CimInstance Win32_Process | Where-Object Name -match 'haq|setup|\.tmp|powershell|msedge|chrome' |
      Format-Table ProcessId, ParentProcessId, Name, CommandLine -Wrap | Out-String -Width 200 | Write-Host
    throw 'the setup did not finish within 5 minutes'
  }
  if ($p.ExitCode) { throw "the setup gave code $($p.ExitCode)" }
}
# The system PATH as written, unexpanded: the setup adds its folder, and the uninstall takes only that off.
function Get-MachinePath { (Get-Item 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Environment').GetValue('Path', '', 'DoNotExpandEnvironmentNames') }
function Get-PathCount { @((Get-MachinePath) -split ';' | Where-Object { $_ -eq $app }).Count }
$pathBefore = Get-MachinePath
Invoke-Setup "$env:RUNNER_TEMP\install.log" @('/MODE=counter', "/ANSWERS=$answers")
if ((Get-PathCount) -ne 1) { throw 'the setup did not put its folder on the PATH' }
if (Test-Path $answers) { throw 'the setup left the answers file' }
if (-not (Test-Path "$app\haq-helper.exe")) { throw 'the helper is not installed' }
if (-not (Test-Path C:\ProgramData\HAQ\installed)) { throw 'the counter is not marked installed' }
if ((& "$app\haq-helper.exe" service state) -ne 'running') { throw 'the service is not running' }
if (-not (Get-ChildItem Cert:\LocalMachine\Root | Where-Object Thumbprint -eq $ca.Thumbprint)) { throw 'the certificate is not trusted' }
$link = Join-Path $env:ProgramData 'Microsoft\Windows\Start Menu\Programs\StartUp\HAQ Retail OS.lnk'
if (-not (Test-Path $link)) { throw 'there is no start-up shortcut' }
# Windows Update never restarts a shop PC from 6:00 to 23:00 (D98): the setting and the policy.
$hours = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\WindowsUpdate\UX\Settings'
if ($hours.ActiveHoursStart -ne 6 -or $hours.ActiveHoursEnd -ne 23 -or $hours.SmartActiveHoursState -ne 0) { throw "the active hours are not 6:00 to 23:00: $($hours | Out-String)" }
$policy = Get-ItemProperty 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate'
if ($policy.SetActiveHours -ne 1 -or $policy.ActiveHoursStart -ne 6 -or $policy.ActiveHoursEnd -ne 23) { throw "the active hours policy is not set: $($policy | Out-String)" }

# A second run is an update: it asks nothing, and the service runs again from the new files.
Invoke-Setup "$env:RUNNER_TEMP\update.log" @('/MODE=counter')
if ((& "$app\haq-helper.exe" service state) -ne 'running') { throw 'the service is not running after the update' }
if ((Get-PathCount) -ne 1) { throw "the update left its folder on the PATH $(Get-PathCount) times" }

$uninstaller = Get-ChildItem $app -Filter 'unins*.exe' | Select-Object -First 1
Start-Process $uninstaller.FullName -ArgumentList '/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART'
$deadline = (Get-Date).AddSeconds(60)
while (Test-Path "$app\haq-helper.exe") { if ((Get-Date) -gt $deadline) { throw 'the uninstall left the helper' }; Start-Sleep 1 }
if ((Get-Service HAQHelper -ErrorAction SilentlyContinue)) { throw 'the service is still there' }
if (Get-ChildItem Cert:\LocalMachine\Root | Where-Object Thumbprint -eq $ca.Thumbprint) { throw 'the certificate is still trusted' }
if (Test-Path $link) { throw 'the shortcut is still there' }
if ((Get-ItemProperty 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate' -ErrorAction SilentlyContinue).SetActiveHours) { throw 'the active hours policy is still there' }
if (-not (Test-Path C:\ProgramData\HAQ\helper.json)) { throw "the uninstall took the shop's settings" }
# The uninstaller ends after its files go, so the PATH is waited for.
$deadline = (Get-Date).AddSeconds(30)
while ((Get-MachinePath) -ne $pathBefore) {
  if ((Get-Date) -gt $deadline) { throw "the PATH is not as it was: before $pathBefore; after $(Get-MachinePath)" }
  Start-Sleep 1
}
Stop-Job $shop; Remove-Job $shop -Force
Write-Host 'Installed, updated and uninstalled; the shop''s folder is kept.'
