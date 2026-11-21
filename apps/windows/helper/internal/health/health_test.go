package health

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

func TestDisksReadAsHostHealthWritesThem(t *testing.T) {
	raw := `[
	  {"DeviceId":"0","FriendlyName":"Samsung SSD 870 EVO 500GB","BusType":"SATA","HealthStatus":"Healthy","Wear":3},
	  {"DeviceId":"1","FriendlyName":"WDC WD10EZEX","BusType":"SATA","HealthStatus":"Warning","Wear":null},
	  {"DeviceId":"2","FriendlyName":"SanDisk Ultra","BusType":"USB","HealthStatus":"Healthy","Wear":null},
	  {"DeviceId":"3","FriendlyName":"","BusType":"NVMe","HealthStatus":"Unknown","Wear":140}
	]`
	disks, err := ParseDisks([]byte(raw))
	if err != nil {
		t.Fatal(err)
	}
	got, _ := json.Marshal(disks)
	want := `[{"device":"PhysicalDisk0","model":"Samsung SSD 870 EVO 500GB","passed":true,"life_left":97},` +
		`{"device":"PhysicalDisk1","model":"WDC WD10EZEX","passed":false,"life_left":null},` +
		`{"device":"PhysicalDisk3","model":null,"passed":null,"life_left":null}]`
	if string(got) != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
}

func TestOneDiskIsAnObjectNotAList(t *testing.T) {
	disks, err := ParseDisks([]byte(`{"DeviceId":"0","FriendlyName":"KINGSTON SA400","BusType":"SATA","HealthStatus":"Healthy","Wear":0}`))
	if err != nil || len(disks) != 1 || *disks[0].LifeLeft != 100 {
		t.Fatalf("got %+v, %v", disks, err)
	}
}

func TestTheClockReadsFromW32tm(t *testing.T) {
	for name, tc := range map[string]struct {
		out  string
		want string
	}{
		"synced": {"Leap Indicator: 0(no warning)\r\nStratum: 4 (secondary reference)\r\nSource: time.windows.com,0x9\r\n", "true"},
		"never synced, offline": {
			"Leap Indicator: 3(not synchronized)\r\nStratum: 0 (unspecified)\r\nSource: Local CMOS Clock\r\n", "false",
		},
		"free-running":        {"Leap Indicator: 0(no warning)\r\nSource: Free-running System Clock\r\n", "false"},
		"the service stopped": {"The following error occurred: The service has not been started. (0x80070426)\r\n", "null"},
		"another language":    {"Schaltsekundenindikator: 0\r\nQuelle: time.windows.com\r\n", "null"},
	} {
		t.Run(name, func(t *testing.T) {
			got, _ := json.Marshal(ParseTimeSync(tc.out))
			if string(got) != tc.want {
				t.Fatalf("got %s, want %s", got, tc.want)
			}
		})
	}
}

func TestBitLockerReadsAsOnOffOrUnknown(t *testing.T) {
	for out, want := range map[string]*bool{"1\r\n": ptr(true), "0": ptr(false), "none\n": ptr(false), "2": nil, "unknown": nil, "": nil} {
		got := ParseBitLocker(out)
		switch {
		case want == nil && got != nil:
			t.Errorf("%q: want unknown, got %+v", out, got)
		case want != nil && (got == nil || got.System == nil || *got.System != *want || got.Backup == nil || *got.Backup != *want):
			t.Errorf("%q: want %v on both disks, got %+v", out, *want, got)
		}
	}
	raw, err := json.Marshal(Build(time.Unix(0, 0), nil, nil, nil))
	if err != nil || strings.Contains(string(raw), "encryption") {
		t.Errorf("a report with no encryption read should leave it out: %s %v", raw, err)
	}
}

func TestAReportWritesAndReadsBackWithItsAge(t *testing.T) {
	dir := t.TempDir()
	at := time.Unix(1_800_000_000, 0)
	if err := Write(dir, Build(at, nil, errors.New("no counters"), nil)); err != nil {
		t.Fatal(err)
	}
	raw, age, err := Read(dir, at.Add(20*time.Minute))
	if err != nil {
		t.Fatal(err)
	}
	if age != 20*time.Minute {
		t.Fatalf("age %s", age)
	}
	// The server's host-report needs written_at as a whole number and disks as a list, even an empty one.
	for _, want := range []string{`"written_at": 1800000000`, `"smartctl": false`, `"disks": []`, `"time_synced": null`} {
		if !strings.Contains(string(raw), want) {
			t.Fatalf("%s is missing %s", raw, want)
		}
	}
}
