// Package bridge is the HAQ print bridge (D87): it makes a printer on this PC look like a network printer
// on port 9100, so the shop server prints to it exactly as to one (app/Printing/NetworkPrinter.php). It has
// no print logic of its own: a receipt's bytes go to the printer as they came, and the printer's answer to
// the status query goes back.
//
// It listens only while the printer is there, so a server that finds nobody listening reads the printer as
// offline, and the counter offers Print pending receipt, as with a network printer that is switched off.
package bridge

import (
	"bufio"
	"context"
	"errors"
	"io"
	"net"
	"net/netip"
	"sync"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/escpos"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/printer"
)

// Limits the server's own timeouts set: it waits 1 second for the status answer (NetworkPrinter's 1 s).
const (
	statusWait  = 800 * time.Millisecond
	queryWait   = 300 * time.Millisecond
	jobWait     = 15 * time.Second
	maxJob      = 4 << 20 // a receipt is a few kB; anything this big is not a receipt
	cacheMaxAge = time.Minute
)

// Config is one bridge: a printer, and where it listens.
type Config struct {
	Listen  string          // ":9100"
	Printer printer.Printer //
	// Allow says who may print: the shop server. Nil lets in whoever the firewall lets in.
	Allow Allow
	// Probe is how often it looks whether the printer is there.
	Probe time.Duration
	Logf  func(format string, args ...any)
}

// Allow says whether a connection from ip may print. It is asked for each connection, so it can follow an
// address that changes, such as WSL's own network on the PC that runs the server.
type Allow func(ip netip.Addr) bool

// Only lets in the given addresses and networks; an address is a network of one.
func Only(prefixes ...netip.Prefix) Allow {
	return func(ip netip.Addr) bool {
		for _, p := range prefixes {
			if p.Contains(ip) {
				return true
			}
		}
		return false
	}
}

// Bridge passes a server's jobs and status queries to one printer, one at a time.
type Bridge struct {
	cfg  Config
	busy sync.Mutex // held while the printer has a job or a query

	mu        sync.Mutex
	listening bool
	last      []byte // the printer's last answer to the status query
	lastAt    time.Time
}

// New makes a bridge; Run starts it.
func New(cfg Config) *Bridge {
	if cfg.Listen == "" {
		cfg.Listen = ":9100"
	}
	if cfg.Probe <= 0 {
		cfg.Probe = 2 * time.Second
	}
	if cfg.Logf == nil {
		cfg.Logf = func(string, ...any) {}
	}
	return &Bridge{cfg: cfg}
}

// Listening says whether it takes connections now, which it does only while the printer is there.
func (b *Bridge) Listening() bool {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.listening
}

// Run listens while the printer is there and stops listening while it is not, until ctx ends.
func (b *Bridge) Run(ctx context.Context) error {
	for ctx.Err() == nil {
		if !b.cfg.Printer.Present() {
			if !sleep(ctx, b.cfg.Probe) {
				break
			}
			continue
		}
		ln, err := net.Listen("tcp", b.cfg.Listen)
		if err != nil {
			b.cfg.Logf("cannot listen on %s: %v", b.cfg.Listen, err)
			if !sleep(ctx, b.cfg.Probe) {
				break
			}
			continue
		}
		b.setListening(true)
		b.cfg.Logf("%s is there: listening on %s", b.cfg.Printer.Name(), b.cfg.Listen)
		stop := make(chan struct{})
		go func() {
			ticker := time.NewTicker(b.cfg.Probe)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					ln.Close()
					return
				case <-stop:
					return
				case <-ticker.C:
					if !b.cfg.Printer.Present() {
						b.cfg.Logf("%s is gone: no longer listening, so the server reads it as offline", b.cfg.Printer.Name())
						ln.Close()
						return
					}
				}
			}
		}()
		b.serve(ln)
		close(stop)
		b.setListening(false)
	}
	return nil
}

func (b *Bridge) setListening(on bool) {
	b.mu.Lock()
	b.listening = on
	b.mu.Unlock()
}

func (b *Bridge) serve(ln net.Listener) {
	var wg sync.WaitGroup
	defer wg.Wait()
	for {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			b.handle(conn)
		}()
	}
}

func (b *Bridge) handle(conn net.Conn) {
	defer conn.Close()
	if !b.allowed(conn.RemoteAddr()) {
		b.cfg.Logf("refused %s: not the shop server", conn.RemoteAddr())
		return
	}
	_ = conn.SetReadDeadline(time.Now().Add(jobWait))
	in := bufio.NewReader(conn)
	head, err := in.Peek(2)
	if err != nil {
		return
	}
	if escpos.IsStatusQuery(head) {
		b.answerStatus(conn, in)
		return
	}
	// A job: the whole receipt first, which the server sends at once and then closes, so the printer is
	// held only while it prints.
	job, err := io.ReadAll(io.LimitReader(in, maxJob+1))
	if err != nil && !errors.Is(err, io.EOF) {
		b.cfg.Logf("a job from %s stopped before its end: %v", conn.RemoteAddr(), err)
		return
	}
	if len(job) > maxJob {
		b.cfg.Logf("refused a job of more than %d bytes from %s", maxJob, conn.RemoteAddr())
		return
	}
	if err := b.Print(job); err != nil {
		b.cfg.Logf("could not print %d bytes from %s: %v", len(job), conn.RemoteAddr(), err)
		return
	}
	b.cfg.Logf("printed %d bytes from %s", len(job), conn.RemoteAddr())
}

// Print sends one job to the printer, waiting for any job before it.
func (b *Bridge) Print(job []byte) error {
	b.busy.Lock()
	defer b.busy.Unlock()
	dev, err := b.cfg.Printer.Open()
	if err != nil {
		return err
	}
	if _, err := dev.Write(job); err != nil {
		dev.Close()
		return err
	}
	return dev.Close()
}

// answerStatus answers the status query: from the printer when it is free, else from its last answer, so
// a query never waits behind a receipt longer than the server waits.
func (b *Bridge) answerStatus(conn net.Conn, in *bufio.Reader) {
	_ = conn.SetReadDeadline(time.Now().Add(queryWait))
	query := make([]byte, 0, len(escpos.StatusQuery))
	buf := make([]byte, 16)
	for len(query) < len(escpos.StatusQuery) {
		n, err := in.Read(buf)
		query = append(query, buf[:n]...)
		if err != nil {
			break
		}
	}
	var answer []byte
	if b.busy.TryLock() {
		answer = b.ask(query)
		b.busy.Unlock()
	}
	if answer == nil {
		answer = b.cached()
	}
	if answer != nil {
		_, _ = conn.Write(answer)
	}
	// No answer at all reads as ready at the server, as a printer that does not answer status queries.
}

func (b *Bridge) ask(query []byte) []byte {
	dev, err := b.cfg.Printer.Open()
	if err != nil {
		return nil
	}
	defer dev.Close()
	var answer []byte
	switch d := dev.(type) {
	case printer.Queued:
		answer = escpos.Answer(d.State())
	case printer.TwoWay:
		if _, err := d.Write(query); err != nil {
			return nil
		}
		want := escpos.Questions(query)
		answer = make([]byte, 0, want)
		deadline := time.Now().Add(statusWait)
		buf := make([]byte, 8)
		for len(answer) < want {
			left := time.Until(deadline)
			if left <= 0 {
				break
			}
			n, err := d.ReadTimeout(buf, left)
			answer = append(answer, buf[:n]...)
			if err != nil || n == 0 {
				break
			}
		}
		if len(answer) < want {
			return nil
		}
		answer = answer[:want]
	default:
		return nil
	}
	b.mu.Lock()
	b.last, b.lastAt = append([]byte(nil), answer...), time.Now()
	b.mu.Unlock()
	return answer
}

func (b *Bridge) cached() []byte {
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.last == nil || time.Since(b.lastAt) > cacheMaxAge {
		return nil
	}
	return append([]byte(nil), b.last...)
}

func (b *Bridge) allowed(addr net.Addr) bool {
	if b.cfg.Allow == nil {
		return true
	}
	tcp, ok := addr.(*net.TCPAddr)
	if !ok {
		return false
	}
	ip, ok := netip.AddrFromSlice(tcp.IP)
	if !ok {
		return false
	}
	return b.cfg.Allow(ip.Unmap())
}

func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}
