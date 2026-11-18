package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestASavedConfigLoadsBackWithItsDefaults(t *testing.T) {
	path := filepath.Join(t.TempDir(), "helper.json")
	in := Config{Mode: Server, Address: "10.92.139.200", Server: "10.92.139.200", Printer: "XP-80C"}
	if err := Save(path, in); err != nil {
		t.Fatal(err)
	}
	out, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	want := in
	want.Distribution, want.Listen = DefaultDistribution, DefaultListen
	if out != want {
		t.Fatalf("loaded %+v, want %+v", out, want)
	}
}

func TestACounterNeedsNoDistributionOrPrinter(t *testing.T) {
	path := filepath.Join(t.TempDir(), "helper.json")
	if err := Save(path, Config{Mode: Counter, Address: "192.168.1.21", Server: "192.168.1.10"}); err != nil {
		t.Fatal(err)
	}
	c, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if c.Distribution != "" || c.Printer != "" || c.Listen != DefaultListen {
		t.Fatalf("got %+v", c)
	}
}

func TestWrongSettingsAreRefusedWithTheReason(t *testing.T) {
	for name, tc := range map[string]struct {
		c    Config
		want string
	}{
		"no mode":         {Config{Address: "10.0.0.2", Server: "10.0.0.2"}, "server or counter"},
		"no address":      {Config{Mode: Counter, Server: "10.0.0.2"}, "this PC's address"},
		"an IPv6 address": {Config{Mode: Counter, Address: "fe80::1", Server: "10.0.0.2"}, "not an IPv4"},
		"a name":          {Config{Mode: Counter, Address: "10.0.0.3", Server: "haq-server.local"}, "the server's address"},
		"a server elsewhere": {
			Config{Mode: Server, Address: "10.0.0.3", Server: "10.0.0.2"}, "this PC's own",
		},
		"a bad distribution": {
			Config{Mode: Server, Address: "10.0.0.2", Server: "10.0.0.2", Distribution: "haq server"}, "distribution",
		},
		"a bad listen address": {
			Config{Mode: Counter, Address: "10.0.0.3", Server: "10.0.0.2", Listen: "9100"}, "listen",
		},
	} {
		t.Run(name, func(t *testing.T) {
			err := Save(filepath.Join(t.TempDir(), "helper.json"), tc.c)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("got %v, want an error about %q", err, tc.want)
			}
		})
	}
}

func TestAMistypedFieldIsAnError(t *testing.T) {
	path := filepath.Join(t.TempDir(), "helper.json")
	raw := `{"mode":"counter","address":"10.0.0.3","server":"10.0.0.2","printr":"XP-80C"}`
	if err := os.WriteFile(path, []byte(raw), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(path); err == nil || !strings.Contains(err.Error(), "printr") {
		t.Fatalf("got %v, want an error naming the unknown field", err)
	}
}

func TestTheFoldersFollowHAQHome(t *testing.T) {
	t.Setenv("HAQ_HOME", filepath.Join("x", "HAQ"))
	if Path() != filepath.Join("x", "HAQ", "helper.json") || StatusDir() != filepath.Join("x", "HAQ", "status") {
		t.Fatalf("Path %s, StatusDir %s", Path(), StatusDir())
	}
}
