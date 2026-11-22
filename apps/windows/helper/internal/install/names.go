package install

import (
	"crypto/rand"
	"fmt"
	"io"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// The names the setup gives what it makes on a PC, so updating and removing find them again.
const (
	// account owns the haq-server distribution and runs it, hidden; nobody signs in as it (D86).
	account = "HAQServer"
	// batchRight lets a scheduled task sign the account in: Windows refuses it otherwise, and Task Scheduler
	// does not grant it (found by the spike, 18 November 2026).
	batchRight = "SeBatchLogonRight"
	taskFolder = `\HAQ Retail OS\`
	runnerTask = "HAQ Runner"
	serverTask = "HAQ Server"
	ruleGroup  = "HAQ Retail OS"
	// caSubject is the shop's certificate authority, as install.sh names it.
	caSubject = "HAQ Retail OS shop authority"
	// installedMark says a first install finished, the server's or a counter's, so a later setup only
	// updates the Windows parts and asks nothing.
	installedMark = "installed"
)

// newPassword makes HAQServer's password: random, with each kind of character a password rule may ask for,
// and never shown or kept; the setup sets a new one each time it runs.
func newPassword() string { return "aZ#7" + rand.Text()[:24] }

// wslPath is where a folder under C:\ is seen from inside the distribution: C:\ProgramData\HAQ is
// /mnt/c/ProgramData/HAQ.
func wslPath(windowsPath string) (string, error) {
	clean := filepath.Clean(windowsPath)
	if len(clean) < 3 || clean[1] != ':' || (clean[2] != '\\' && clean[2] != '/') {
		return "", fmt.Errorf("%s is not on a drive", windowsPath)
	}
	rest := strings.ReplaceAll(clean[3:], `\`, "/")
	return "/mnt/" + strings.ToLower(clean[:1]) + "/" + rest, nil
}

func installDir() string { return filepath.Join(config.Dir(), "install") }

// step prints one step of the install, numbered, for the person watching the setup's window.
type steps struct {
	out   io.Writer
	n, of int
}

func (s *steps) next(what string) {
	s.n++
	fmt.Fprintf(s.out, "\n[%d of %d] %s\n", s.n, s.of, what)
}

// PrinterChoice is this PC's receipt printer, as `haq-helper printer` sets it after the install (D139):
// Printer, a printer on this PC as `haq-helper printers` names it, reached through the print bridge; or
// Host, a network receipt printer's address; or neither, for no printer yet.
type PrinterChoice struct {
	Printer string
	Host    string
}

// target is what the shop server is told this counter prints to: this PC's bridge, the network printer,
// or none.
func (c PrinterChoice) target(address string) string {
	switch {
	case c.Printer != "":
		return address
	case c.Host != "":
		return c.Host
	}
	return "none"
}

func (c PrinterChoice) describe() string {
	switch {
	case c.Printer != "":
		return c.Printer + ", on this PC, through the print bridge"
	case c.Host != "":
		return "the network printer at " + c.Host
	}
	return "no printer yet: receipts wait as Print pending until one is set"
}

// counterFromMark is the counter a counter PC was installed as, from its installed mark ("counter T02 of
// 192.168.1.10, ..."), or a placeholder when the mark does not say.
func counterFromMark(mark string) string {
	if m := counterMark.FindStringSubmatch(mark); m != nil {
		return m[1]
	}
	return "<this counter, such as T02>"
}

var counterMark = regexp.MustCompile(`^counter (T[0-9]{2}) of `)
