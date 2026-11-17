//go:build windows

package printer

import (
	"bytes"
	"os"
	"testing"
	"time"
)

// A print queue takes a RAW job byte for byte: CI makes one on a file port, standing in for a USB receipt
// printer's queue (D87). Set HAQ_TEST_QUEUE to its name and HAQ_TEST_QUEUE_FILE to its file to run it.
func TestAPrintQueueTakesARawJobByteForByte(t *testing.T) {
	queue, file := os.Getenv("HAQ_TEST_QUEUE"), os.Getenv("HAQ_TEST_QUEUE_FILE")
	if queue == "" || file == "" {
		t.Skip("needs a print queue on a file port: HAQ_TEST_QUEUE and HAQ_TEST_QUEUE_FILE, as CI sets them")
	}

	list, err := List()
	if err != nil {
		t.Fatal(err)
	}
	var listed *Found
	for i := range list {
		if list[i].Name == queue {
			listed = &list[i]
		}
	}
	if listed == nil {
		t.Fatalf("%s is not in the list: %+v", queue, list)
	}
	if listed.Kind != KindQueue || !listed.Present {
		t.Fatalf("%s is listed as %s, there now %v", queue, listed.Kind, listed.Present)
	}

	p, err := Choose(queue)
	if err != nil {
		t.Fatal(err)
	}
	dev, err := p.Open()
	if err != nil {
		t.Fatal(err)
	}
	q, ok := dev.(Queued)
	if !ok {
		t.Fatal("a print queue should answer the status query from its state")
	}
	if paperOut, coverOpen := q.State(); paperOut || coverOpen {
		t.Fatalf("an idle queue reads paper out %v, cover open %v", paperOut, coverOpen)
	}
	// Every byte value, as a receipt's barcode, drawer kick and cut need them, then some text.
	job := []byte{0x1b, 0x40}
	for b := 0; b < 256; b++ {
		job = append(job, byte(b))
	}
	job = append(job, []byte("Thing item 1  N1,500.00\n")...)
	if _, err := dev.Write(job); err != nil {
		t.Fatal(err)
	}
	if err := dev.Close(); err != nil {
		t.Fatal(err)
	}

	deadline := time.Now().Add(30 * time.Second)
	for {
		got, _ := os.ReadFile(file)
		if bytes.Equal(got, job) {
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("after 30 seconds the port has %d bytes, not the %d sent", len(got), len(job))
		}
		time.Sleep(200 * time.Millisecond)
	}
}
