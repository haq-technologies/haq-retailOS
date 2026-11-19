package install

import (
	"crypto/rand"
	"fmt"
	"io"
	"path/filepath"
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
