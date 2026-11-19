package install

import (
	"strings"
	"testing"
)

func TestTheServerRunsOnWindows10WithMicrosoftsCurrentWSL(t *testing.T) {
	for _, pc := range []struct{ build, revision uint32 }{
		{19045, 6456}, // 22H2, kept up to date
		{19045, 2311}, // 22H2 with the November 2022 update
		{19044, 5011}, // 21H2 and LTSC 2021
		{19043, 2364}, // 21H1, updated to December 2022
		{19042, 2311}, // 20H2, Enterprise and Education
		{22000, 194},  // Windows 11 21H2
		{26100, 4061}, // Windows 11 24H2
		{20348, 2582}, // Windows Server 2022, CI's runner
	} {
		if p := serverWindowsProblem(pc.build, pc.revision, ""); p != "" {
			t.Errorf("%d.%d was refused: %s", pc.build, pc.revision, p)
		}
	}
}

func TestOlderWindows10IsAskedToUpdateAndCanStillBeACounter(t *testing.T) {
	for _, pc := range []struct {
		build, revision uint32
		version         string
	}{
		{19045, 2130, "22H2"}, // 22H2 as it first shipped, before the November 2022 update
		{19044, 1288, "21H2"},
		{19041, 1415, "2004"}, // 2004 stopped at December 2021
		{18363, 2274, "1909"},
		{17763, 6893, "1809"}, // and LTSC 2019
		{10240, 20000, ""},
	} {
		p := serverWindowsProblem(pc.build, pc.revision, pc.version)
		if p == "" {
			t.Fatalf("%d.%d was taken", pc.build, pc.revision)
		}
		for _, want := range []string{"Windows Update", "22H2", "counter as it is"} {
			if !strings.Contains(p, want) {
				t.Errorf("%d.%d: %q does not say %q", pc.build, pc.revision, p, want)
			}
		}
	}
	if p := serverWindowsProblem(18363, 2274, "1909"); !strings.Contains(p, "Windows 10 version 1909 (build 18363.2274)") {
		t.Errorf("it does not name the PC's Windows: %q", p)
	}
}

func TestLowMemoryIsAWarningThatSaysHowMuch(t *testing.T) {
	if got := (LowMemoryError{GB: 3.9}).Error(); !strings.Contains(got, "3.9 GB") || !strings.Contains(got, "8 GB") {
		t.Fatalf("%q", got)
	}
}
