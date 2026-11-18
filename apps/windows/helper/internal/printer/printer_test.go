package printer

import "testing"

func TestWindowsOwnPrintersAreNotReceiptPrinters(t *testing.T) {
	for _, tc := range []struct {
		f       Found
		virtual bool
	}{
		{Found{Name: "Microsoft Print to PDF", Kind: KindQueue, Port: "PORTPROMPT:"}, true},
		{Found{Name: "Fax", Kind: KindQueue, Port: "SHRFAX:"}, true},
		{Found{Name: "OneNote (Desktop)", Kind: KindQueue, Port: "nul:"}, true},
		{Found{Name: "EPSON TM-T20III Receipt", Kind: KindQueue, Port: "ESDPRT001"}, false}, // Epson's own port
		{Found{Name: "XP-80C", Kind: KindUSB, Port: "USB001"}, false},
		{Found{Name: "POS-58", Kind: KindCOM, Port: "COM3"}, false},
	} {
		if got := Virtual(tc.f); got != tc.virtual {
			t.Errorf("%s on %s: virtual %v, want %v", tc.f.Name, tc.f.Port, got, tc.virtual)
		}
	}
}
