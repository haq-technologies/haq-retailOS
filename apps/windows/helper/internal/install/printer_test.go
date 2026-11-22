package install

import "testing"

func TestCounterFromMark(t *testing.T) {
	for mark, want := range map[string]string{
		"counter T02 of 192.168.1.10, 2026-11-22T18:00:00Z\n":   "T02",
		"counter T19 of haq-server.local, 2026-11-22T18:00:00Z": "T19",
		"server 192.168.1.10, 2026-11-22T18:00:00Z\n":           "<this counter, such as T02>",
		"": "<this counter, such as T02>",
	} {
		if got := counterFromMark(mark); got != want {
			t.Errorf("counterFromMark(%q) = %q, want %q", mark, got, want)
		}
	}
}

func TestPrinterChoiceTarget(t *testing.T) {
	for _, c := range []struct {
		choice PrinterChoice
		want   string
	}{
		{PrinterChoice{Printer: "POS-80 Printer"}, "192.168.1.10"},
		{PrinterChoice{Host: "192.168.1.21"}, "192.168.1.21"},
		{PrinterChoice{}, "none"},
	} {
		if got := c.choice.target("192.168.1.10"); got != c.want {
			t.Errorf("%+v.target = %q, want %q", c.choice, got, c.want)
		}
	}
}
