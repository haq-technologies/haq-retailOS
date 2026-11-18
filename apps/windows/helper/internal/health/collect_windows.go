//go:build windows

package health

import (
	"context"
	"os/exec"
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

// Collect reads the disks and the clock now.
func Collect(ctx context.Context) Report {
	disksCtx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	var disks []Disk
	out, err := exec.CommandContext(disksCtx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-Command", disksScript).Output()
	if err == nil {
		disks, err = ParseDisks(out)
	}
	timeCtx, cancelTime := context.WithTimeout(ctx, 30*time.Second)
	defer cancelTime()
	// w32tm fails when the time service is stopped; its message then reads as unknown.
	status, _ := exec.CommandContext(timeCtx, "w32tm.exe", "/query", "/status").CombinedOutput()
	return Build(time.Now(), disks, err, ParseTimeSync(string(status)))
}
