package support

import (
	"archive/zip"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"testing"
	"time"
)

// A PC's folder as the setup leaves it, with what must never leave it beside what support needs.
func helperFolder(t *testing.T) string {
	t.Helper()
	root := t.TempDir()
	files := map[string]string{
		"logs/service.log":    "2026-11-20T19:47:25Z the bridge listens on :9100\n",
		"logs/setup.log":      "=== setup server\nGHCR token ghp_abcdefghijklmnopqrstuvwxyz0123456789 typed once\n",
		"logs/service.log.1":  "older lines\n",
		"status/host.json":    `{"written_at": 1, "disks": []}`,
		"config.json":         `{"mode": "server"}`,
		"install/answers.json": `{"pin": "135790"}`,
		"install/ghcr_token":  "ghp_abcdefghijklmnopqrstuvwxyz0123456789",
		"runner/queue.json":   `{"argv": []}`,
	}
	for name, body := range files {
		path := filepath.Join(root, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return root
}

func read(t *testing.T, path string) map[string]string {
	t.Helper()
	zr, err := zip.OpenReader(path)
	if err != nil {
		t.Fatal(err)
	}
	defer zr.Close()
	out := map[string]string{}
	for _, f := range zr.File {
		r, err := f.Open()
		if err != nil {
			t.Fatal(err)
		}
		b, _ := io.ReadAll(r)
		r.Close()
		out[f.Name] = string(b)
	}
	return out
}

func TestTheSupportFileHoldsTheLogsAndTheReportAndNothingSecret(t *testing.T) {
	root := helperFolder(t)
	server := filepath.Join(t.TempDir(), "server-support.zip")
	if err := os.WriteFile(server, []byte("PK the server's own file"), 0o600); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "haq-support.zip")
	if err := Write(path, Files{Root: root, ServerZip: server, Version: "2026.12.1", Mode: "server", Now: time.Date(2026, 11, 21, 9, 0, 0, 0, time.UTC)}); err != nil {
		t.Fatal(err)
	}

	got := read(t, path)
	var names []string
	for name := range got {
		names = append(names, name)
	}
	sort.Strings(names)
	want := []string{"about.txt", "helper/logs/service.log", "helper/logs/service.log.1", "helper/logs/setup.log", "helper/status/host.json", "server/haq-support.zip"}
	if strings.Join(names, " ") != strings.Join(want, " ") {
		t.Fatalf("the file holds %v, want %v", names, want)
	}
	if strings.Contains(got["helper/logs/setup.log"], "ghp_") || !strings.Contains(got["helper/logs/setup.log"], "[a token, left out]") {
		t.Fatalf("the token was not left out: %q", got["helper/logs/setup.log"])
	}
	if !strings.Contains(got["about.txt"], "2026-11-21T09:00:00Z") || !strings.Contains(got["about.txt"], "2026.12.1") {
		t.Fatalf("about.txt: %q", got["about.txt"])
	}
	// On Windows the folder's ACL decides; elsewhere the file is the owner's alone.
	if runtime.GOOS != "windows" {
		info, err := os.Stat(path)
		if err != nil || info.Mode().Perm()&0o077 != 0 {
			t.Fatalf("the file is open to others: %v %v", info, err)
		}
	}
}

func TestACounterGivesItsOwnPartOnly(t *testing.T) {
	path := filepath.Join(t.TempDir(), "haq-support.zip")
	if err := Write(path, Files{Root: helperFolder(t), Mode: "counter", Now: time.Now()}); err != nil {
		t.Fatal(err)
	}
	if _, ok := read(t, path)["server/haq-support.zip"]; ok {
		t.Fatal("a counter has no server part")
	}
}

func TestItNeverWritesOverAFileThatIsThere(t *testing.T) {
	path := filepath.Join(t.TempDir(), "haq-support.zip")
	if err := os.WriteFile(path, []byte("keep me"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := Write(path, Files{Root: helperFolder(t), Now: time.Now()}); err == nil {
		t.Fatal("it wrote over a file")
	}
	if b, _ := os.ReadFile(path); string(b) != "keep me" {
		t.Fatalf("the file there was changed: %q", b)
	}
}

func TestRedactLeavesOutHashesKeysTokensAndPasswords(t *testing.T) {
	in := `set "pin_hash" = $argon2id$v=19$m=65536,t=4,p=1$abc$def, APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldY== password=hunter2 GHCR_TOKEN: github_pat_11ABCDEFG0123456789_abcdefghij`
	got := string(Redact([]byte(in)))
	for _, secret := range []string{"argon2id", "QUJDREVG", "hunter2", "github_pat_"} {
		if strings.Contains(got, secret) {
			t.Fatalf("%q is still in %q", secret, got)
		}
	}
	if !strings.Contains(got, `"pin_hash" = [a PIN hash, left out]`) {
		t.Fatalf("got %q", got)
	}
}

func TestWSLPathIsTheWindowsPathAsTheServerSeesIt(t *testing.T) {
	got, err := WSLPath(`C:\ProgramData\HAQ\runner\out\server-support.zip`)
	if err != nil || got != "/mnt/c/ProgramData/HAQ/runner/out/server-support.zip" {
		t.Fatalf("got %q, %v", got, err)
	}
	if _, err := WSLPath(`runner\out`); err == nil {
		t.Fatal("a relative path has no place inside")
	}
}
