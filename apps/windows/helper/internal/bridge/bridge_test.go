package bridge

import (
	"bytes"
	"context"
	"errors"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/escpos"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/printer"
)

// fake is a two-way printer, as on its USB printing device: it keeps what it is sent, answers the status
// query with its paper and cover state, and refuses a second open while one is open.
type fake struct {
	present  atomic.Bool
	paperOut atomic.Bool
	slow     atomic.Int64 // nanoseconds each write takes
	mu       sync.Mutex
	open     bool
	received bytes.Buffer
	overlaps int
}

func newFake() *fake { f := &fake{}; f.present.Store(true); return f }

func (f *fake) Name() string  { return "fake printer" }
func (f *fake) Present() bool { return f.present.Load() }
func (f *fake) Open() (printer.Device, error) {
	if !f.present.Load() {
		return nil, errors.New("unplugged")
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.open {
		f.overlaps++
		return nil, errors.New("already open")
	}
	f.open = true
	return &fakeDevice{f: f}, nil
}

func (f *fake) got() []byte {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]byte(nil), f.received.Bytes()...)
}

type fakeDevice struct {
	f       *fake
	pending []byte
}

func (d *fakeDevice) Write(p []byte) (int, error) {
	time.Sleep(time.Duration(d.f.slow.Load()))
	if escpos.IsStatusQuery(p) {
		d.pending = escpos.Answer(d.f.paperOut.Load(), false)
		return len(p), nil
	}
	d.f.mu.Lock()
	defer d.f.mu.Unlock()
	return d.f.received.Write(p)
}

func (d *fakeDevice) ReadTimeout(p []byte, _ time.Duration) (int, error) {
	n := copy(p, d.pending)
	d.pending = d.pending[n:]
	return n, nil
}

func (d *fakeDevice) Close() error {
	d.f.mu.Lock()
	defer d.f.mu.Unlock()
	d.f.open = false
	return nil
}

// start runs a bridge for p on a free local port and returns its address.
func start(t *testing.T, p printer.Printer, allow ...netip.Addr) (string, *Bridge) {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := ln.Addr().String()
	ln.Close()
	b := New(Config{Listen: addr, Printer: p, Allow: allow, Probe: 50 * time.Millisecond, Logf: t.Logf})
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = b.Run(ctx); close(done) }()
	t.Cleanup(func() { cancel(); <-done })
	waitFor(t, b.Listening)
	return addr, b
}

func waitFor(t *testing.T, ok func() bool) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for !ok() {
		if time.Now().After(deadline) {
			t.Fatal("waited 3 seconds in vain")
		}
		time.Sleep(10 * time.Millisecond)
	}
}

// send and status do what app/Printing/NetworkPrinter.php does.
func send(addr string, job []byte) error {
	conn, err := net.DialTimeout("tcp", addr, 3*time.Second)
	if err != nil {
		return err
	}
	if _, err := conn.Write(job); err != nil {
		conn.Close()
		return err
	}
	return conn.Close()
}

func status(t *testing.T, addr string) string {
	t.Helper()
	conn, err := net.DialTimeout("tcp", addr, 1500*time.Millisecond)
	if err != nil {
		return "offline"
	}
	defer conn.Close()
	_, _ = conn.Write(escpos.StatusQuery)
	_ = conn.SetReadDeadline(time.Now().Add(time.Second))
	answer := make([]byte, 0, 2)
	buf := make([]byte, 2)
	for len(answer) < 2 {
		n, err := conn.Read(buf)
		answer = append(answer, buf[:n]...)
		if err != nil {
			break
		}
	}
	switch {
	case len(answer) < 2:
		return "ready" // as the server reads a printer that does not answer
	case answer[0]&0x60 != 0:
		return "paper_out"
	case answer[1]&0x04 != 0:
		return "cover_open"
	}
	return "ready"
}

func TestAJobReachesThePrinterWhole(t *testing.T) {
	f := newFake()
	addr, _ := start(t, f)
	job := append([]byte{0x1b, 0x40}, bytes.Repeat([]byte("Thing item 1  N1,500.00\n"), 200)...)
	if err := send(addr, job); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { return len(f.got()) == len(job) })
	if !bytes.Equal(f.got(), job) {
		t.Fatal("the printer got different bytes from those sent")
	}
}

func TestTheStatusQueryIsAnsweredByThePrinter(t *testing.T) {
	f := newFake()
	addr, _ := start(t, f)
	if got := status(t, addr); got != "ready" {
		t.Fatalf("status %s, not ready", got)
	}
	f.paperOut.Store(true)
	if got := status(t, addr); got != "paper_out" {
		t.Fatalf("status %s, not paper_out", got)
	}
	if len(f.got()) != 0 {
		t.Fatal("a status query was printed")
	}
}

// While a slow receipt prints, a status query gets the printer's last answer at once, inside the server's
// one second, instead of waiting behind the receipt.
func TestAStatusQueryDuringAJobGetsTheLastAnswer(t *testing.T) {
	f := newFake()
	f.paperOut.Store(true)
	addr, _ := start(t, f)
	if got := status(t, addr); got != "paper_out" {
		t.Fatalf("first status %s", got)
	}
	f.slow.Store(int64(2 * time.Second))
	go func() { _ = send(addr, []byte{0x1b, 0x40, 'x'}) }()
	time.Sleep(200 * time.Millisecond)
	began := time.Now()
	if got := status(t, addr); got != "paper_out" {
		t.Fatalf("status during the job %s, not the last answer", got)
	}
	if took := time.Since(began); took > time.Second {
		t.Fatalf("the status query waited %s behind the job", took)
	}
}

func TestJobsPrintOneAtATime(t *testing.T) {
	f := newFake()
	f.slow.Store(int64(30 * time.Millisecond))
	addr, _ := start(t, f)
	var wg sync.WaitGroup
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			_ = send(addr, []byte{0x1b, 0x40, byte('a' + i), '\n'})
		}(i)
	}
	wg.Wait()
	waitFor(t, func() bool { return len(f.got()) == 8*4 })
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.overlaps != 0 {
		t.Fatalf("the printer was opened twice at once %d times", f.overlaps)
	}
}

// Unplugged, the bridge stops listening, so the server reads the printer as offline and the counter offers
// Print pending receipt; plugged back, it listens again.
func TestAnUnpluggedPrinterReadsAsOffline(t *testing.T) {
	f := newFake()
	addr, b := start(t, f)
	f.present.Store(false)
	waitFor(t, func() bool { return !b.Listening() })
	if got := status(t, addr); got != "offline" {
		t.Fatalf("status %s, not offline", got)
	}
	if err := send(addr, []byte{0x1b, 0x40, 'x'}); err == nil {
		t.Fatal("a job was taken while the printer was unplugged")
	}
	f.present.Store(true)
	waitFor(t, b.Listening)
	if got := status(t, addr); got != "ready" {
		t.Fatalf("status %s after plugging back, not ready", got)
	}
}

func TestOnlyTheShopServerMayPrint(t *testing.T) {
	f := newFake()
	addr, _ := start(t, f, netip.MustParseAddr("10.9.8.7"))
	_ = send(addr, []byte{0x1b, 0x40, 'x'})
	time.Sleep(200 * time.Millisecond)
	if len(f.got()) != 0 {
		t.Fatal("a job from another address was printed")
	}
}

// A printer reached only through its print queue answers from the queue's state: a file stands in for one.
func TestAPrintQueueAnswersFromItsState(t *testing.T) {
	path := filepath.Join(t.TempDir(), "receipts.prn")
	addr, _ := start(t, printer.File{Path: path})
	if got := status(t, addr); got != "ready" {
		t.Fatalf("status %s, not ready", got)
	}
	if err := send(addr, []byte{0x1b, 0x40, 'o', 'k'}); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { b, _ := os.ReadFile(path); return len(b) == 4 })
}
