//go:build windows

package install

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/service"
)

// Counter makes this PC a counter of a shop whose server is another PC: the shop's certificate trusted, the
// print bridge for its own receipt printer, the counter opened full screen at sign-in, and the page that
// registers it.
func Counter(ctx context.Context, a Answers, out io.Writer) (Result, error) {
	if a.Mode != config.Counter {
		return Result{}, errors.New("these answers are for the PC that runs the shop")
	}
	s := steps{out: out, of: 4}

	s.next("Checking this PC")
	if err := Preflight(ctx, out, config.Counter, a.Address, false); err != nil {
		return Result{}, err
	}

	s.next("Trusting the shop's certificate")
	// The folders first: on a new counter nothing else has made them.
	if err := folders(ctx, out, false); err != nil {
		return Result{}, err
	}
	ca, err := fetch(ctx, "http://"+a.Server+"/ca.crt")
	if err != nil {
		return Result{}, fmt.Errorf("could not reach the server at %s: %w. Is it on, and is this PC on the shop's network?", a.Server, err)
	}
	ca, err = certificate(ca)
	if err != nil {
		return Result{}, err
	}
	published, err := fetch(ctx, "http://"+a.Server+"/ca.thumbprint")
	if err != nil {
		return Result{}, err
	}
	thumb, _ := thumbprint(ca)
	if !sameThumbprint(thumb, string(published)) {
		return Result{}, fmt.Errorf("the certificate (%s) is not the one the server names (%s): stop, and call HAQ support",
			thumb, strings.TrimSpace(string(published)))
	}
	if err := trust(ctx, out, ca); err != nil {
		return Result{}, err
	}
	fmt.Fprintln(out, "The shop's certificate:", thumb, "- the server's PC shows the same with: haq-helper server certificate")

	s.next("Starting the HAQ Helper")
	cfg := config.Config{Mode: config.Counter, Address: a.Address, Server: a.Server, Printer: a.Printer}
	if err := config.Save(config.Path(), cfg); err != nil {
		return Result{}, err
	}
	result := Result{EnrolURL: "https://" + a.Server + "/enrol#code=" + a.Code, Thumbprint: thumb}
	if a.Printer != "" {
		if err := firewall(ctx, out, false, a.Server); err != nil {
			return Result{}, err
		}
		if err := startService(); err != nil {
			return Result{}, err
		}
		result.PrinterLine = fmt.Sprintf("haq-helper server printer %s %s", a.Counter, a.Address)
	} else {
		// A counter with a network printer has no bridge.
		_ = service.Remove()
		_ = removeFirewall(ctx)
	}

	s.next("Opening the counter whenever Windows starts")
	if err := counterShortcut(ctx, out, a.Server); err != nil {
		return Result{}, err
	}
	neverSleep(ctx)
	// A newer setup run here later updates the Windows parts and asks nothing.
	mark := fmt.Sprintf("counter %s of %s, %s\n", a.Counter, a.Server, time.Now().UTC().Format(time.RFC3339))
	if err := config.WriteFile(filepath.Join(config.Dir(), installedMark), []byte(mark)); err != nil {
		return Result{}, err
	}
	fmt.Fprintf(out, "\nThis PC is ready to be counter %s.\n", a.Counter)
	if result.PrinterLine != "" {
		fmt.Fprintf(out, "On the server's PC, in Terminal (Admin), point %s at this PC's printer:\n  %s\n", a.Counter, result.PrinterLine)
	}
	return result, nil
}

func fetch(ctx context.Context, url string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s answered %s", url, resp.Status)
	}
	return io.ReadAll(io.LimitReader(resp.Body, 64<<10))
}
