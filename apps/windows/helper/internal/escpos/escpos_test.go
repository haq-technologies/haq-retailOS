package escpos

import (
	"bytes"
	"testing"
)

func TestStatusQueryIsToldFromAReceipt(t *testing.T) {
	if !IsStatusQuery(StatusQuery) {
		t.Fatal("the server's status query is not recognised")
	}
	// A receipt and a drawer kick from the server both start with ESC @.
	for _, job := range [][]byte{{0x1b, 0x40, 'H', 'i'}, {0x1b, 0x40, 0x1b, 0x70, 0x00, 0x19, 0xfa}, {0x10}, {}} {
		if IsStatusQuery(job) {
			t.Fatalf("% x was taken for a status query", job)
		}
	}
}

func TestQuestionsCountsTheAnswersToWaitFor(t *testing.T) {
	if got := Questions(StatusQuery); got != 2 {
		t.Fatalf("the status query asks %d questions, not 2", got)
	}
	if got := Questions([]byte{0x10, 0x04, 0x01}); got != 1 {
		t.Fatalf("one DLE EOT counted as %d", got)
	}
}

// The server reads the answer as app/Printing/NetworkPrinter.php does: paper end is 0x60 in the first byte,
// an open cover 0x04 in the second.
func TestAnswerReadsAsTheServerReadsIt(t *testing.T) {
	read := func(a []byte) string {
		switch {
		case a[0]&0x60 != 0:
			return "paper_out"
		case a[1]&0x04 != 0:
			return "cover_open"
		}
		return "ready"
	}
	cases := []struct {
		paper, cover bool
		want         string
	}{{false, false, "ready"}, {true, false, "paper_out"}, {false, true, "cover_open"}, {true, true, "paper_out"}}
	for _, c := range cases {
		if got := read(Answer(c.paper, c.cover)); got != c.want {
			t.Errorf("paper out %v, cover open %v: read as %s, not %s", c.paper, c.cover, got, c.want)
		}
	}
}

func TestTestReceiptHoldsTheScanCheckAndCuts(t *testing.T) {
	r := TestReceipt([]string{"HAQ Retail OS", "Test receipt"})
	if !bytes.HasPrefix(r, []byte{0x1b, 0x40}) {
		t.Fatal("the test receipt does not start with ESC @")
	}
	if IsStatusQuery(r) {
		t.Fatal("the test receipt reads as a status query")
	}
	if bytes.Count(r, []byte(ScanCheck)) != 2 {
		t.Fatal("the barcode and the QR code should each hold the scan check")
	}
	if !bytes.HasSuffix(r, []byte{0x1d, 0x56, 0x42, 0x00}) {
		t.Fatal("the test receipt does not end with a cut")
	}
	// The QR code's store command gives the data's length plus 3, low byte first.
	store := []byte{0x1d, 0x28, 0x6b, byte(len(ScanCheck) + 3), 0x00, 0x31, 0x50, 0x30}
	if !bytes.Contains(r, store) {
		t.Fatal("the QR code's store command has the wrong length")
	}
}
