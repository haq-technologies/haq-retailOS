//go:build !windows

package keepalive

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/drives"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/health"
)

// fakeWSL stands in for wsl.exe: it notes each call's arguments, keeps what a host-report is given, and has
// the distribution stop at once, as one that keeps failing would.
func fakeWSL(t *testing.T) (exe, calls, stdin string) {
	t.Helper()
	dir := t.TempDir()
	exe, calls, stdin = filepath.Join(dir, "wsl"), filepath.Join(dir, "calls"), filepath.Join(dir, "stdin")
	script := `#!/bin/sh
printf '%s\n' "$*" >> "` + calls + `"
case "$*" in
  *host-report*) cat > "` + stdin + `" ;;
  *copy-windows*) echo "Copied 3 backups to HAQ-OFFSITE-A." ;;
  *"/bin/sleep infinity"*) echo "The distribution stopped." >&2; exit 1 ;;
esac
`
	if err := os.WriteFile(exe, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	return exe, calls, stdin
}

type logs struct {
	mu    sync.Mutex
	lines []string
}

func (l *logs) logf(format string, args ...any) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.lines = append(l.lines, strings.TrimSpace(fmt.Sprintf(format, args...)))
}

func run(t *testing.T, c Config, d time.Duration) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), d)
	defer cancel()
	Run(ctx, c)
}

func readCalls(t *testing.T, path string) []string {
	t.Helper()
	raw, err := os.ReadFile(path)
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		t.Fatal(err)
	}
	return strings.Split(strings.TrimSpace(string(raw)), "\n")
}

func TestADistributionThatStopsIsStartedAgainAsRoot(t *testing.T) {
	exe, calls, _ := fakeWSL(t)
	var l logs
	run(t, Config{WSL: exe, Distribution: "haq-server", StatusDir: t.TempDir(), Logf: l.logf,
		Backoff: 20 * time.Millisecond, MaxBackoff: 40 * time.Millisecond, HealthFirst: time.Hour}, 500*time.Millisecond)

	got := readCalls(t, calls)
	if len(got) < 3 {
		t.Fatalf("it started the distribution %d times in half a second, with 20 to 40 ms between", len(got))
	}
	for _, call := range got {
		if call != "-d haq-server -u root -e /bin/sleep infinity" {
			t.Fatalf("called wsl.exe with %q", call)
		}
	}
	if !strings.Contains(strings.Join(l.lines, "\n"), "The distribution stopped.") {
		t.Fatalf("the log should say why it stopped: %v", l.lines)
	}
}

func TestThePCsReportGoesIntoTheServerAsItIs(t *testing.T) {
	exe, calls, stdin := fakeWSL(t)
	status := t.TempDir()
	if err := health.Write(status, health.Build(time.Now(), nil, nil, nil)); err != nil {
		t.Fatal(err)
	}
	want, _ := os.ReadFile(filepath.Join(status, health.FileName))
	run(t, Config{WSL: exe, Distribution: "haq-server", StatusDir: status, Backoff: time.Hour,
		HealthFirst: time.Millisecond, HealthEvery: time.Hour}, 300*time.Millisecond)

	got, err := os.ReadFile(stdin)
	if err != nil || string(got) != string(want) {
		t.Fatalf("host-report was given %q (%v), want %q", got, err, want)
	}
	if !strings.Contains(strings.Join(readCalls(t, calls), "\n"), "-d haq-server -u root -e /usr/local/bin/haq host-report") {
		t.Fatalf("calls: %v", readCalls(t, calls))
	}
}

func TestAnOldReportIsNotPassedOn(t *testing.T) {
	exe, _, stdin := fakeWSL(t)
	status := t.TempDir()
	if err := health.Write(status, health.Build(time.Now().Add(-3*time.Hour), nil, nil, nil)); err != nil {
		t.Fatal(err)
	}
	var l logs
	run(t, Config{WSL: exe, Distribution: "haq-server", StatusDir: status, Logf: l.logf, Backoff: time.Hour,
		HealthFirst: time.Millisecond, HealthEvery: time.Hour}, 300*time.Millisecond)

	if _, err := os.Stat(stdin); err == nil {
		t.Fatal("a report three hours old was passed on")
	}
	if !strings.Contains(strings.Join(l.lines, "\n"), "HAQ Helper service running") {
		t.Fatalf("the log should say the report is old: %v", l.lines)
	}
}

func TestAnOffsiteDrivePluggedInIsCopiedToOnce(t *testing.T) {
	exe, calls, _ := fakeWSL(t)
	there := []drives.Drive{{Letter: "C:", Label: "Windows"}, {Letter: "E:", Label: "HAQ-OFFSITE-A", Serial: 7}}
	var l logs
	run(t, Config{WSL: exe, Distribution: "haq-server", StatusDir: t.TempDir(), Logf: l.logf, Backoff: time.Hour,
		HealthFirst: time.Hour, DrivesEvery: 10 * time.Millisecond,
		Drives: func() ([]drives.Drive, error) { return there, nil }}, 300*time.Millisecond)

	copies := 0
	for _, call := range readCalls(t, calls) {
		if strings.Contains(call, "copy-windows") {
			copies++
			if call != "-d haq-server -u root -e /opt/haq-retail-os/bin/offsite.sh copy-windows E: A" {
				t.Fatalf("called wsl.exe with %q", call)
			}
		}
	}
	if copies != 1 {
		t.Fatalf("it copied %d times to a drive left plugged in", copies)
	}
	if !strings.Contains(strings.Join(l.lines, "\n"), "Copied 3 backups to HAQ-OFFSITE-A.") {
		t.Fatalf("the log should say what the copy said: %v", l.lines)
	}
}
