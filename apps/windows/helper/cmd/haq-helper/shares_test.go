package main

import (
	"strings"
	"testing"
)

func TestARestoreFromADriveAsksTwoSharesAndSendsThemAsInput(t *testing.T) {
	k := &typed{answers: []string{"haq-1-0a1b", " haq-3-9f8e "}}
	input, err := restoreShares([]string{"restore", "--from-windows-drive", "E:", "A"}, k.ask)
	if err != nil {
		t.Fatal(err)
	}
	if input != "haq-1-0a1b\nhaq-3-9f8e\n" {
		t.Fatalf("input %q", input)
	}
	if len(k.prompts) != 2 || !strings.Contains(k.prompts[0], "starts with haq-") {
		t.Fatalf("prompts %q", k.prompts)
	}
}

func TestTheSameShareTwiceOrNotAShareRestoresNothing(t *testing.T) {
	for _, answers := range [][]string{
		{"haq-1-0a1b", "haq-1-0a1b"},
		{"haq-1-0a1b", "1-9f8e"},
		{"haq-1-0a1b", "haq-2 9f8e"},
		{"haq-1-0a1b"},
	} {
		if _, err := restoreShares([]string{"restore", "--from-drive", "/dev/sdb1"}, (&typed{answers: answers}).ask); err == nil {
			t.Fatalf("%q was taken", answers)
		}
	}
}

func TestOtherCommandsAndARestoreFromTheBackupDiskAskNoShares(t *testing.T) {
	for _, args := range [][]string{{"restore"}, {"backups"}, {"update", "--version", "2026.12.1"}, {}} {
		k := &typed{}
		input, err := restoreShares(args, k.ask)
		if err != nil || input != "" || len(k.prompts) != 0 {
			t.Fatalf("%q: input %q, %v, asked %q", args, input, err, k.prompts)
		}
	}
}
