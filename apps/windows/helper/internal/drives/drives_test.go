package drives

import (
	"reflect"
	"testing"
)

func TestOnlyTheOffsiteLabelsCount(t *testing.T) {
	for label, want := range map[string]string{"HAQ-OFFSITE-A": "A", "haq-offsite-b ": "B", "HAQ-OFFSITE-C": "", "Windows": ""} {
		got, ok := Which(label)
		if got != want || ok != (want != "") {
			t.Errorf("%q: %q %v", label, got, ok)
		}
	}
}

func TestEachPlugInIsCopiedToOnce(t *testing.T) {
	system := Drive{Letter: "C:", Label: "Windows", Serial: 1}
	a := Drive{Letter: "E:", Label: "HAQ-OFFSITE-A", Serial: 0xA}
	b := Drive{Letter: "E:", Label: "HAQ-OFFSITE-B", Serial: 0xB}
	var w Watcher
	steps := []struct {
		now  []Drive
		want []Drive
	}{
		{[]Drive{system, a}, []Drive{a}}, // A was plugged in when the PC started
		{[]Drive{system, a}, nil},        // still there: copied already
		{[]Drive{system}, nil},           // taken away
		{[]Drive{system, b}, []Drive{b}}, // B brought back, on the same letter
		{[]Drive{system, b}, nil},
		{[]Drive{system}, nil},
		{[]Drive{system, b}, []Drive{b}}, // B plugged in again: a new copy
	}
	for i, s := range steps {
		if got := w.Plugged(s.now); !reflect.DeepEqual(got, s.want) {
			t.Fatalf("step %d: got %v, want %v", i, got, s.want)
		}
	}
}
