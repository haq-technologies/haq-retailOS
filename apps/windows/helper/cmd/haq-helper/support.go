package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/install"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/support"
)

// runSupportFile writes the file this PC gives HAQ support (finding LG7). On the server's PC the server makes its
// own part inside its Linux, written where HAQServer may write (the runner's output folder), and that copy is
// removed once it is in the file: it holds the shop's logs.
func runSupportFile(args []string) error {
	fs := flag.NewFlagSet("support-file", flag.ExitOnError)
	out := fs.String("out", "", "the ZIP to write; by default haq-support-<time>.zip in this folder")
	_ = fs.Parse(args)

	now := time.Now().UTC()
	path := *out
	if path == "" {
		path = fmt.Sprintf("haq-support-%s.zip", now.Format("20060102T150405Z"))
	}
	cfg, err := config.Load(config.Path())
	if err != nil {
		return err
	}
	files := support.Files{Root: config.Dir(), Version: version, Mode: string(cfg.Mode), Now: now}

	if cfg.Mode == config.Server {
		inside := filepath.Join(install.RunnerDir(), "out", "server-support.zip")
		_ = os.Remove(inside)
		defer os.Remove(inside)
		at, err := support.WSLPath(inside)
		if err != nil {
			return err
		}
		argv := []string{"wsl.exe", "-d", cfg.Distribution, "-u", "root", "-e", "/usr/local/bin/haq", "support-file", "--out", at}
		code, err := install.AsServerWithInput(context.Background(), os.Stdout, "", argv...)
		switch {
		case err != nil:
			fmt.Fprintln(os.Stderr, "The server's own part could not be made:", err, "- the file holds this PC's part only.")
		case code != 0:
			fmt.Fprintln(os.Stderr, "The server's own part could not be made; the file holds this PC's part only.")
		default:
			files.ServerZip = inside
		}
	}

	if err := support.Write(path, files); err != nil {
		return err
	}
	if abs, err := filepath.Abs(path); err == nil {
		path = abs
	}
	fmt.Printf("Wrote %s.\nGive it only to HAQ support: it holds the shop's logs and staff names.\n", path)
	return nil
}
