// Package escpos knows the few ESC/POS bytes the print bridge must understand itself (D87): the server's
// status query and its answer, and the test receipt the setup prints. Everything else the bridge passes on
// to the printer unread.
package escpos

import "bytes"

// StatusQuery is what the server sends to ask a printer's status (app/Printing/NetworkPrinter.php):
// DLE EOT 4, the paper sensor, then DLE EOT 2, the offline cause. Each command answers one byte.
var StatusQuery = []byte{0x10, 0x04, 0x04, 0x10, 0x04, 0x02}

// IsStatusQuery says whether a connection that starts with these bytes asks for the status. A receipt and a
// drawer kick start with ESC @ (0x1b 0x40), so the first two bytes are enough to tell them apart.
func IsStatusQuery(head []byte) bool {
	return len(head) >= 2 && head[0] == 0x10 && head[1] == 0x04
}

// Questions counts the DLE EOT commands in a status query, which is how many bytes the printer answers.
func Questions(query []byte) int {
	n := 0
	for i := 0; i+2 < len(query); i++ {
		if query[i] == 0x10 && query[i+1] == 0x04 {
			n++
			i += 2
		}
	}
	return n
}

// Answer is the reply a printer gives to StatusQuery, for a printer the bridge cannot ask itself (one
// reached only through its Windows print queue). Bits 1 and 4 are always set; paper end sets bits 5 and 6
// of the first byte, and an open cover sets bit 2 of the second, as the server reads them.
func Answer(paperOut, coverOpen bool) []byte {
	paper, cover := byte(0x12), byte(0x12)
	if paperOut {
		paper |= 0x60
	}
	if coverOpen {
		cover |= 0x04
	}
	return []byte{paper, cover}
}

// ScanCheck is the text the test receipt's QR code and barcode hold. It has capitals, digits and the signs
// a scanner types with Shift, so the setup's scanner check shows a keyboard layout that does not match.
const ScanCheck = "HAQ-Test:Scan/Check?ok=1"

// TestReceipt is the setup's test receipt: a few lines, a Code 128 barcode and a QR code of ScanCheck,
// then a feed and a partial cut. The lines must be plain ASCII.
func TestReceipt(lines []string) []byte {
	var b bytes.Buffer
	b.Write([]byte{0x1b, 0x40})       // ESC @: initialise
	b.Write([]byte{0x1b, 0x61, 0x01}) // ESC a 1: centre
	for _, line := range lines {
		b.WriteString(line)
		b.WriteByte('\n')
	}
	b.WriteByte('\n')

	// Code 128, code set B, with the text printed under it.
	code := "{B" + ScanCheck
	b.Write([]byte{0x1d, 0x68, 0x50})                  // GS h: 80 dots high
	b.Write([]byte{0x1d, 0x77, 0x02})                  // GS w: module width 2
	b.Write([]byte{0x1d, 0x48, 0x02})                  // GS H: text below
	b.Write([]byte{0x1d, 0x6b, 0x49, byte(len(code))}) // GS k 73: Code 128
	b.WriteString(code)
	b.WriteString("\n\n")

	// QR code, model 2, module size 6, error correction M.
	data := []byte(ScanCheck)
	store := len(data) + 3
	b.Write([]byte{0x1d, 0x28, 0x6b, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00})
	b.Write([]byte{0x1d, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x43, 0x06})
	b.Write([]byte{0x1d, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x45, 0x31})
	b.Write([]byte{0x1d, 0x28, 0x6b, byte(store % 256), byte(store / 256), 0x31, 0x50, 0x30})
	b.Write(data)
	b.Write([]byte{0x1d, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x51, 0x30})
	b.WriteString("\n")

	b.Write([]byte{0x1b, 0x61, 0x00})       // ESC a 0: left
	b.Write([]byte{0x1d, 0x56, 0x42, 0x00}) // GS V 66: feed, then a partial cut
	return b.Bytes()
}
