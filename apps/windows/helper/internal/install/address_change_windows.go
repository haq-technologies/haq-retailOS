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
)

// ChangeAddress moves this PC to a new fixed address after the install, keeping the shop and its sales
// (D140). On the PC that runs the shop it changes the server's own address inside (`haq address`, which
// makes its certificate again under the same authority), the port forwarding, the settings and the start-up
// shortcut, then makes counter T01's new registration code. On a counter, server says the shop server's
// address changed: the settings, the print bridge's firewall rule and the shortcut follow it; otherwise it
// is the counter's own address, which its print bridge is reached at.
func ChangeAddress(ctx context.Context, out io.Writer, address string, server bool) error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("run it in Terminal (Admin)")
	}
	if err := privateIPv4(address); err != nil {
		return err
	}
	cfg, err := config.Load(config.Path())
	if err != nil {
		return fmt.Errorf("this PC has no HAQ Retail OS to change: %w", err)
	}

	switch {
	case cfg.Mode == config.Server && server:
		return errors.New("this PC runs the shop: give its own new address, haq-helper address " + address)
	case cfg.Mode == config.Server:
		return serverAddress(ctx, out, cfg, address)
	case server:
		cfg.Server = address
		if err := config.Save(config.Path(), cfg); err != nil {
			return err
		}
		if cfg.Printer != "" {
			if err := firewall(ctx, out, false, address); err != nil {
				return err
			}
			if err := startService(); err != nil {
				return err
			}
		}
		if err := counterShortcut(ctx, out, address); err != nil {
			return err
		}
		mark, _ := os.ReadFile(filepath.Join(config.Dir(), installedMark))
		counter := counterFromMark(string(mark))
		fmt.Fprintf(out, "\nThis counter now opens the shop at https://%s.\nIt registers again: on the server's PC, in Terminal (Admin), run\n  haq-helper server enrol %s\nand open the link it gives here, in Chrome.\n", address, counter)
		return nil
	default:
		if err := hasAddress(address); err != nil {
			return err
		}
		cfg.Address = address
		if err := config.Save(config.Path(), cfg); err != nil {
			return err
		}
		if cfg.Printer != "" {
			if err := startService(); err != nil {
				return err
			}
			mark, _ := os.ReadFile(filepath.Join(config.Dir(), installedMark))
			fmt.Fprintf(out, "\nThis counter's address is %s now. Its printer is reached there: on the server's PC, in Terminal (Admin), run\n  haq-helper server printer %s %s\n", address, counterFromMark(string(mark)), address)
		} else {
			fmt.Fprintf(out, "\nThis counter's address is %s now. It has no printer of its own, so the server needs nothing more.\n", address)
		}
		return nil
	}
}

// serverAddress moves the PC that runs the shop: the server inside first, so a refusal there changes
// nothing on Windows, then Windows' own parts, then T01's new code.
func serverAddress(ctx context.Context, out io.Writer, cfg config.Config, address string) error {
	old := cfg.Address
	if address == old {
		fmt.Fprintf(out, "This PC's address is %s already.\n", address)
		return nil
	}
	if err := hasAddress(address); err != nil {
		return err
	}
	fmt.Fprintf(out, "Moving the shop server from %s to %s...\n", old, address)
	if err := inside(ctx, out, cfg.Distribution, "/usr/local/bin/haq", "address", address); err != nil {
		return err
	}
	removeForward(ctx, old)
	if err := forward(ctx, out, address); err != nil {
		return err
	}
	cfg.Address, cfg.Server = address, address
	if err := config.Save(config.Path(), cfg); err != nil {
		return err
	}
	if err := counterShortcut(ctx, out, address); err != nil {
		return err
	}
	if err := startService(); err != nil {
		return err
	}
	fmt.Fprintln(out, "\nThis PC, counter T01, registers again at the new address. Open the link below in Chrome on this PC:")
	if err := inside(ctx, out, cfg.Distribution, "/usr/local/bin/haq", "enrol", "T01"); err != nil {
		return fmt.Errorf("the address is changed, but T01's code was not made: %w; run haq-helper server enrol T01", err)
	}
	return nil
}
