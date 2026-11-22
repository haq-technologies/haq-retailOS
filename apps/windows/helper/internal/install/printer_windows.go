package install

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/service"
)

// SetPrinter changes this PC's receipt printer after the install, as the setup's printer page sets it
// (D139): a printer on this PC through the print bridge, a network printer, or none. It saves the choice,
// opens or closes the bridge's firewall rule, starts or removes the HAQ Helper service, and points this
// PC's counter at it on the server: itself on the server's PC (T01), or the line to run there on a counter.
func SetPrinter(ctx context.Context, out io.Writer, choice PrinterChoice) error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("run it in Terminal (Admin)")
	}
	if choice.Printer != "" && choice.Host != "" {
		return errBothPrint
	}
	if choice.Host != "" && !printerHost.MatchString(choice.Host) {
		return fmt.Errorf("the printer's address %q is not an address", choice.Host)
	}
	cfg, err := config.Load(config.Path())
	if err != nil {
		return fmt.Errorf("this PC has no HAQ Retail OS to change: run the setup first: %w", err)
	}
	cfg.Printer = choice.Printer
	if err := config.Save(config.Path(), cfg); err != nil {
		return err
	}

	switch cfg.Mode {
	case config.Server:
		bridgeFrom := ""
		if cfg.Printer != "" {
			bridgeFrom = "LocalSubnet" // the server inside WSL; the bridge itself lets in only WSL's own network
		}
		if err := firewall(ctx, out, true, bridgeFrom); err != nil {
			return err
		}
		// The server's PC always runs the service: it keeps the server's report, with or without a bridge.
		if err := startService(); err != nil {
			return err
		}
	case config.Counter:
		if cfg.Printer != "" {
			if err := firewall(ctx, out, false, cfg.Server); err != nil {
				return err
			}
			if err := startService(); err != nil {
				return err
			}
		} else {
			// A counter with a network printer, or none, has no bridge.
			_ = service.Remove()
			_ = removeFirewall(ctx)
		}
	}

	target := choice.target(cfg.Address)
	if cfg.Mode == config.Server {
		fmt.Fprintf(out, "Pointing counter T01 at %s on the shop server...\n", target)
		if err := inside(ctx, out, cfg.Distribution, "/usr/local/bin/haq", "printer", "T01", target); err != nil {
			return fmt.Errorf("this PC is set, but the shop server was not: %w; run haq-helper server printer T01 %s", err, target)
		}
		fmt.Fprintln(out, "\nDone. Counter T01 prints to", choice.describe())
		return nil
	}
	mark, _ := os.ReadFile(filepath.Join(config.Dir(), installedMark))
	fmt.Fprintf(out, "\nThis PC is set: it prints to %s.\nOn the server's PC, in Terminal (Admin), point this counter at it:\n  haq-helper server printer %s %s\n",
		choice.describe(), counterFromMark(string(mark)), target)
	return nil
}
