// Package keepalive keeps the hidden shop server running on a Windows PC (D86). It runs as the account that
// owns the WSL distribution, HAQServer, from Windows' start, before anyone signs in, as the spike proved
// (apps/windows/spike). It:
//   - holds the distribution open, since WSL stops one soon after its last Windows process ends, and starts
//     it again whenever it stops;
//   - passes the PC's own report (disks and clock), which the HAQ Helper service writes, into the server for
//     its health page, through `haq host-report`, every 15 minutes;
//   - starts the server's off-site copy when an off-site drive is plugged in.
package keepalive

import (
	"bytes"
	"context"
	"io"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/drives"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/health"
)

// Where install.sh puts the server's own commands inside the distribution.
const (
	haq     = "/usr/local/bin/haq"
	offsite = "/opt/haq-retail-os/bin/offsite.sh"
)

// Config is what the keep-alive needs; zero timings take the defaults.
type Config struct {
	WSL          string // wsl.exe
	Distribution string
	StatusDir    string // where the HAQ Helper service writes the PC's report
	// Drives lists the PC's drives; nil leaves the off-site drives alone.
	Drives func() ([]drives.Drive, error)
	Logf   func(format string, args ...any)

	Backoff, MaxBackoff      time.Duration // between starts of a distribution that keeps stopping
	HealthFirst, HealthEvery time.Duration
	DrivesEvery              time.Duration
	// HealthMaxAge is the oldest report it passes on: an older one means the service has stopped.
	HealthMaxAge time.Duration
}

func (c *Config) defaults() {
	if c.WSL == "" {
		c.WSL = "wsl.exe"
	}
	if c.Logf == nil {
		c.Logf = func(string, ...any) {}
	}
	set := func(d *time.Duration, v time.Duration) {
		if *d <= 0 {
			*d = v
		}
	}
	set(&c.Backoff, 5*time.Second)
	set(&c.MaxBackoff, time.Minute)
	set(&c.HealthFirst, 2*time.Minute)
	set(&c.HealthEvery, 15*time.Minute)
	set(&c.DrivesEvery, 5*time.Second)
	set(&c.HealthMaxAge, time.Hour)
}

// command runs argv as root inside the distribution, with no shell between.
func (c Config) command(ctx context.Context, stdin io.Reader, argv ...string) *exec.Cmd {
	args := append([]string{"-d", c.Distribution, "-u", "root", "-e"}, argv...)
	cmd := exec.CommandContext(ctx, c.WSL, args...)
	cmd.Env = append(os.Environ(), "WSL_UTF8=1")
	cmd.Stdin = stdin
	return cmd
}

// Run keeps the server running until ctx ends.
func Run(ctx context.Context, c Config) {
	c.defaults()
	var wg sync.WaitGroup
	wg.Go(func() { hold(ctx, c) })
	wg.Go(func() { passHealth(ctx, c) })
	if c.Drives != nil {
		wg.Go(func() { watchDrives(ctx, c) })
	}
	wg.Wait()
}

func hold(ctx context.Context, c Config) {
	backoff := c.Backoff
	for ctx.Err() == nil {
		started := time.Now()
		c.Logf("holding %s open", c.Distribution)
		out, err := c.command(ctx, nil, "/bin/sleep", "infinity").CombinedOutput()
		if ctx.Err() != nil {
			return
		}
		c.Logf("%s stopped after %s (%v) %s; starting it again", c.Distribution, time.Since(started).Round(time.Second), err, lastLines(out, 3))
		if time.Since(started) > 10*time.Minute {
			backoff = c.Backoff // it ran well: start it again soon
		}
		if !sleep(ctx, backoff) {
			return
		}
		backoff = min(backoff*2, c.MaxBackoff)
	}
}

func passHealth(ctx context.Context, c Config) {
	if !sleep(ctx, c.HealthFirst) {
		return
	}
	for {
		raw, age, err := health.Read(c.StatusDir, time.Now())
		switch {
		case err != nil:
			c.Logf("no report of the PC's disks and clock to pass on: %v", err)
		case age > c.HealthMaxAge:
			c.Logf("the report of the PC's disks and clock is %s old: is the HAQ Helper service running?", age.Round(time.Minute))
		default:
			if out, err := c.command(ctx, bytes.NewReader(raw), haq, "host-report").CombinedOutput(); err != nil && ctx.Err() == nil {
				c.Logf("could not pass the PC's report to the server (%v) %s", err, lastLines(out, 3))
			}
		}
		if !sleep(ctx, c.HealthEvery) {
			return
		}
	}
}

func watchDrives(ctx context.Context, c Config) {
	var w drives.Watcher
	for {
		if list, err := c.Drives(); err == nil {
			for _, d := range w.Plugged(list) {
				which, _ := drives.Which(d.Label)
				c.Logf("off-site drive %s is plugged in as %s: copying the backups to it", which, d.Letter)
				out, err := c.command(ctx, nil, offsite, "copy-windows", d.Letter, which).CombinedOutput()
				if ctx.Err() != nil {
					return
				}
				if err != nil {
					c.Logf("the copy to off-site drive %s did not finish (%v):\n%s", which, err, lastLines(out, 20))
				} else {
					c.Logf("off-site drive %s: %s", which, lastLines(out, 1))
				}
			}
		}
		if !sleep(ctx, c.DrivesEvery) {
			return
		}
	}
}

func lastLines(out []byte, n int) string {
	lines := strings.Split(strings.TrimRight(string(out), "\r\n"), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.TrimSpace(strings.Join(lines, "\n"))
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
