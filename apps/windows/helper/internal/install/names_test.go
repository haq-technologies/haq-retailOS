package install

import (
	"regexp"
	"testing"
)

func TestThePasswordHasEveryKindOfCharacter(t *testing.T) {
	seen := map[string]bool{}
	for range 20 {
		p := newPassword()
		if len(p) != 28 || seen[p] {
			t.Fatalf("%q: 28 characters, never the same twice", p)
		}
		seen[p] = true
		for _, kind := range []string{`[a-z]`, `[A-Z]`, `[0-9]`, `[^A-Za-z0-9]`} {
			if !regexp.MustCompile(kind).MatchString(p) {
				t.Fatalf("%q has no %s", p, kind)
			}
		}
	}
}

func TestAWindowsFolderAsSeenFromInside(t *testing.T) {
	for in, want := range map[string]string{
		`C:\ProgramData\HAQ\install`: "/mnt/c/ProgramData/HAQ/install",
		`D:\HAQ`:                     "/mnt/d/HAQ",
	} {
		got, err := wslPath(in)
		if err != nil || got != want {
			t.Errorf("%s: %q, %v; want %q", in, got, err, want)
		}
	}
	if _, err := wslPath(`\\server\share`); err == nil {
		t.Error("a network share is not on a drive")
	}
}
