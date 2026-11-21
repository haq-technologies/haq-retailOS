//go:build windows

package health

import (
	"context"
	"os"
	"os/exec"
	"strings"
	"time"
)

// disksScript lists the physical disks with their health and wear. Reading the wear counter needs an
// administrator or the system account, which the service is.
const disksScript = `$ErrorActionPreference = 'Stop'
$list = @(Get-PhysicalDisk | ForEach-Object {
    $counter = $null
    try { $counter = $_ | Get-StorageReliabilityCounter -ErrorAction Stop } catch { }
    $wear = $null
    if ($counter -and $null -ne $counter.Wear) { $wear = [double]$counter.Wear }
    [pscustomobject]@{
        DeviceId = "$($_.DeviceId)"; FriendlyName = "$($_.FriendlyName)"; BusType = "$($_.BusType)"
        HealthStatus = "$($_.HealthStatus)"; Wear = $wear
    }
})
ConvertTo-Json -InputObject $list -Compress -Depth 3`

// bitLockerScript prints the system drive's BitLocker protection status: 1 on, 0 off, 2 unknown, or "none"
// when the PC has no BitLocker. Reading it needs an administrator or the system account, which the service is.
const bitLockerScript = `try {
    $v = Get-CimInstance -Namespace 'root/cimv2/Security/MicrosoftVolumeEncryption' -ClassName Win32_EncryptableVolume -Filter "DriveLetter='$env:SystemDrive'" -ErrorAction Stop
    if ($null -eq $v) { 'none' } else { "$($v.ProtectionStatus)" }
} catch [Microsoft.Management.Infrastructure.CimException] {
    if ($_.Exception.NativeErrorCode -eq 'InvalidNamespace') { 'none' } else { 'unknown' }
} catch { 'unknown' }`

// Collect reads the disks, the clock and the disk encryption now.
func Collect(ctx context.Context) Report {
	disksCtx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	var disks []Disk
	cmd := exec.CommandContext(disksCtx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-Command", disksScript)
	// Without PowerShell 7's module path, in case it started this program: Windows PowerShell would look there.
	for _, kv := range os.Environ() {
		if !strings.HasPrefix(strings.ToUpper(kv), "PSMODULEPATH=") {
			cmd.Env = append(cmd.Env, kv)
		}
	}
	out, err := cmd.Output()
	if err == nil {
		disks, err = ParseDisks(out)
	}
	timeCtx, cancelTime := context.WithTimeout(ctx, 30*time.Second)
	defer cancelTime()
	// w32tm fails when the time service is stopped; its message then reads as unknown.
	status, _ := exec.CommandContext(timeCtx, "w32tm.exe", "/query", "/status").CombinedOutput()
	r := Build(time.Now(), disks, err, ParseTimeSync(string(status)))
	lockCtx, cancelLock := context.WithTimeout(ctx, 30*time.Second)
	defer cancelLock()
	if out, lockErr := exec.CommandContext(lockCtx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-Command", bitLockerScript).Output(); lockErr == nil {
		r.Encryption = ParseBitLocker(string(out))
	}
	return r
}
