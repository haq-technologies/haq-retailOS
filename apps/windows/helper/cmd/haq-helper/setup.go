package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"path/filepath"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/install"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/logfile"
)

// Exit codes the setup program reads from `setup check`.
const (
	exitNeedsWSL  = 20 // Windows' Linux is not turned on: `setup wsl`, then a restart
	exitLowMemory = 21 // less memory than the server is tested with: the wizard asks, then checks with --warned
)

// runSetup is the install engine the setup program runs, in a window the person installing watches.
func runSetup(args []string) error {
	if len(args) == 0 {
		return errors.New("setup takes check, wsl, server, counter, update, stop, prepare or remove")
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	fs := flag.NewFlagSet("setup "+args[0], flag.ExitOnError)
	answers := fs.String("answers", "", "the setup's answers, a file read once and deleted")
	distro := fs.String("distro", "", "the haq-server distribution, haq-server.tar.gz")
	result := fs.String("result", "", "where to write what the setup program shows at the end")
	mode := fs.String("mode", "server", "server or counter")
	address := fs.String("address", "", "this PC's fixed address")
	deleteShop := fs.Bool("delete-shop-and-sales", false, "remove deletes the shop server and every sale in it, for good")
	warned := fs.Bool("warned", false, "check goes on past a warning the person installing has seen")
	_ = fs.Parse(args[1:])

	// What the install does is kept for support in logs\setup.log as well as shown; never the answers.
	out := io.Writer(os.Stdout)
	var log io.Writer = io.Discard
	if args[0] != "check" {
		if l, err := logfile.Open(filepath.Join(config.LogDir(), "setup.log"), logMax); err == nil {
			defer l.Close()
			fmt.Fprintf(l, "\n=== HAQ Helper %s: setup %s, %s\n", version, args[0], time.Now().UTC().Format(time.RFC3339))
			out, log = io.MultiWriter(os.Stdout, l), l
		}
	}
	err := setup(ctx, args[0], out, *answers, *distro, *result, *mode, *address, *deleteShop, *warned)
	if err != nil {
		fmt.Fprintln(log, "Stopped:", err)
	}
	return err
}

func setup(ctx context.Context, what string, out io.Writer, answers, distro, result, mode, address string, deleteShop, warned bool) error {
	switch what {
	case "check":
		err := install.Preflight(ctx, out, config.Mode(mode), address, !warned)
		if errors.Is(err, install.ErrNoWSL) {
			fmt.Println(err)
			os.Exit(exitNeedsWSL)
		}
		if low := (install.LowMemoryError{}); errors.As(err, &low) {
			fmt.Println(low.Error())
			os.Exit(exitLowMemory)
		}
		return err
	case "wsl":
		return install.InstallWSL(ctx, out)
	case "server", "counter":
		a, err := install.ReadAnswers(answers)
		if err != nil {
			return err
		}
		var r install.Result
		if what == "server" {
			r, err = install.Server(ctx, a, distro, out)
		} else {
			r, err = install.Counter(ctx, a, out)
		}
		if err != nil {
			return err
		}
		return r.Write(result)
	case "update":
		return install.Update(ctx, out)
	case "stop":
		return install.Stop(ctx)
	case "prepare":
		return install.Prepare(ctx, out)
	case "remove":
		return install.Remove(ctx, out, deleteShop)
	}
	return fmt.Errorf("setup takes check, wsl, server, counter, update, stop, prepare or remove, not %q", what)
}

// runServerCommand runs the shop server's own `haq` command inside its distribution, as on the mini-PC.
func runServerCommand(args []string) error {
	cfg, err := config.Load(config.Path())
	if err != nil {
		return err
	}
	if cfg.Mode != config.Server {
		return fmt.Errorf("the shop server runs on another PC, at %s: run it there", cfg.Server)
	}
	args, input, err := withPIN(args, readSecret)
	if err != nil {
		return err
	}
	if input == "" {
		// A restore from an off-site drive: its two key shares (D101).
		if input, err = restoreShares(args, readSecret); err != nil {
			return err
		}
	}
	argv := append([]string{"wsl.exe", "-d", cfg.Distribution, "-u", "root", "-e", "/usr/local/bin/haq"}, args...)
	return exitWith(install.AsServerWithInput(context.Background(), os.Stdout, input, argv...))
}

func runAsServer(args []string) error {
	if len(args) > 0 && args[0] == "--" {
		args = args[1:]
	}
	if len(args) == 0 {
		return errors.New("give the program to run as HAQServer")
	}
	return exitWith(install.AsServer(context.Background(), os.Stdout, args...))
}

func exitWith(code int, err error) error {
	if err != nil {
		return err
	}
	if code != 0 {
		os.Exit(code)
	}
	return nil
}
