// Package health is the PC's own report for the server's health page (spec section 4; check E6) [F24]: each
// disk's health and the life it has left, and whether the clock is set from a time source. The server inside
// WSL cannot read Windows' disks, so on a Windows PC the HAQ Helper writes the report, in the shape
// deploy/host-health.sh writes on the mini-PC, and the keep-alive hands it to `haq host-report` (D86).
package health

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// Report is what host-health.sh writes, field for field.
type Report struct {
	WrittenAt int64 `json:"written_at"`
	// Smartctl says the disks could be read: smartctl on the mini-PC, Windows' storage counters here.
	Smartctl   bool   `json:"smartctl"`
	Disks      []Disk `json:"disks"`
	TimeSynced *bool  `json:"time_synced"`
}

// Disk is one disk's health.
type Disk struct {
	Device   string  `json:"device"`
	Model    *string `json:"model"`
	Passed   *bool   `json:"passed"`
	LifeLeft *int    `json:"life_left"`
}

// FileName is the report's name in the status folder.
const FileName = "host.json"

// windowsDisk is one disk as the collector's PowerShell lists it.
type windowsDisk struct {
	DeviceID     string   `json:"DeviceId"`
	FriendlyName string   `json:"FriendlyName"`
	BusType      string   `json:"BusType"`
	HealthStatus string   `json:"HealthStatus"`
	Wear         *float64 `json:"Wear"`
}

// skipped are buses whose disks are not the PC's own: flash drives, such as the off-site drives, and
// virtual disks.
var skipped = map[string]bool{"USB": true, "File Backed Virtual": true, "SD": true, "MMC": true}

// ParseDisks reads the collector's list of disks: Windows' health status and its wear counter, the share of
// the disk's life used.
func ParseDisks(raw []byte) ([]Disk, error) {
	raw = []byte(strings.TrimSpace(string(raw)))
	var list []windowsDisk
	if len(raw) > 0 && raw[0] == '{' {
		var one windowsDisk
		if err := json.Unmarshal(raw, &one); err != nil {
			return nil, err
		}
		list = []windowsDisk{one}
	} else if len(raw) > 0 {
		if err := json.Unmarshal(raw, &list); err != nil {
			return nil, err
		}
	}
	disks := []Disk{}
	for _, w := range list {
		if skipped[w.BusType] {
			continue
		}
		d := Disk{Device: "PhysicalDisk" + w.DeviceID}
		if name := strings.TrimSpace(w.FriendlyName); name != "" {
			d.Model = &name
		}
		switch w.HealthStatus {
		case "Healthy":
			d.Passed = ptr(true)
		case "Warning", "Unhealthy":
			d.Passed = ptr(false)
		}
		if w.Wear != nil && *w.Wear >= 0 && *w.Wear <= 100 {
			d.LifeLeft = ptr(100 - int(*w.Wear))
		}
		disks = append(disks, d)
	}
	return disks, nil
}

// ParseTimeSync reads `w32tm /query /status`: synced from a time source, or not, or unknown when the
// output is not what English Windows prints.
func ParseTimeSync(out string) *bool {
	var source, leap string
	for _, line := range strings.Split(out, "\n") {
		key, value, ok := strings.Cut(line, ":")
		if !ok {
			continue
		}
		switch strings.TrimSpace(key) {
		case "Source":
			source = strings.TrimSpace(value)
		case "Leap Indicator":
			leap = strings.TrimSpace(value)
		}
	}
	switch {
	case strings.HasPrefix(leap, "3"):
		return ptr(false) // "3(not synchronized)"
	case source == "":
		return nil
	case strings.Contains(source, "Local CMOS Clock"), strings.Contains(source, "Free-running System Clock"):
		return ptr(false)
	default:
		return ptr(true)
	}
}

// Build makes the report from what the collectors read; disksErr says the disks could not be read.
func Build(now time.Time, disks []Disk, disksErr error, timeSynced *bool) Report {
	r := Report{WrittenAt: now.Unix(), Smartctl: disksErr == nil, Disks: disks, TimeSynced: timeSynced}
	if r.Disks == nil {
		r.Disks = []Disk{}
	}
	return r
}

// Write puts the report in the status folder, whole.
func Write(dir string, r Report) error {
	raw, err := json.MarshalIndent(r, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	return config.WriteFile(filepath.Join(dir, FileName), append(raw, '\n'))
}

// Read reads the report from the status folder, and says how old it is.
func Read(dir string, now time.Time) ([]byte, time.Duration, error) {
	raw, err := os.ReadFile(filepath.Join(dir, FileName))
	if err != nil {
		return nil, 0, err
	}
	var r Report
	if err := json.Unmarshal(raw, &r); err != nil {
		return nil, 0, fmt.Errorf("the report is not JSON: %w", err)
	}
	return raw, now.Sub(time.Unix(r.WrittenAt, 0)), nil
}

func ptr[T any](v T) *T { return &v }
