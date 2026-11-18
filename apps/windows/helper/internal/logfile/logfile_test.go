package logfile

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestALogPastItsLimitMovesAsideAndStartsAgain(t *testing.T) {
	path := filepath.Join(t.TempDir(), "logs", "helper.log")
	l, err := Open(path, 100)
	if err != nil {
		t.Fatal(err)
	}
	line := strings.Repeat("a", 39) + "\n" // 40 bytes
	for i := 0; i < 2; i++ {
		if _, err := l.Write([]byte(line)); err != nil {
			t.Fatal(err)
		}
	}
	// A third line would take it to 120 bytes: the first two move to helper.log.1.
	if _, err := l.Write([]byte(strings.Repeat("b", 39) + "\n")); err != nil {
		t.Fatal(err)
	}
	if err := l.Close(); err != nil {
		t.Fatal(err)
	}
	now, _ := os.ReadFile(path)
	old, _ := os.ReadFile(path + ".1")
	if string(now) != strings.Repeat("b", 39)+"\n" || string(old) != line+line {
		t.Fatalf("helper.log %q, helper.log.1 %q", now, old)
	}
}

func TestALogReopenedCarriesOnWhereItWas(t *testing.T) {
	path := filepath.Join(t.TempDir(), "helper.log")
	if err := os.WriteFile(path, []byte(strings.Repeat("x", 90)), 0o644); err != nil {
		t.Fatal(err)
	}
	l, err := Open(path, 100)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := l.Write([]byte("0123456789abc")); err != nil {
		t.Fatal(err)
	}
	l.Close()
	if old, err := os.ReadFile(path + ".1"); err != nil || len(old) != 90 {
		t.Fatalf("the 90 bytes there before should have moved aside: %d bytes, %v", len(old), err)
	}
}
