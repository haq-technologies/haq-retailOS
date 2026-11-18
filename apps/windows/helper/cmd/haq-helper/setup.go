package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/signal"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/install"
)

// Exit codes the setup program reads from `setup check`.
const (
	exitNeedsWSL = 20 // Windows' Linux is not turned on: `setup wsl`, then a restart
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
	_ = fs.Parse(args[1:])

	switch args[0] {
	case "check":
		err := install.Preflight(ctx, os.Stdout, config.Mode(*mode), *address)
		if errors.Is(err, install.ErrNoWSL) {
			fmt.Println(err)
			os.Exit(exitNeedsWSL)
		}
		return err
	case "wsl":
		return install.InstallWSL(ctx, os.Stdout)
	case "server", "counter":
		a, err := install.ReadAnswers(*answers)
		if err != nil {
			return err
		}
		var r install.Result
		if args[0] == "server" {
			r, err = install.Server(ctx, a, *distro, os.Stdout)
		} else {
			r, err = install.Counter(ctx, a, os.Stdout)
		}
		if err != nil {
			return err
		}
		return r.Write(*result)
	case "update":
		return install.Update(ctx, os.Stdout)
	case "stop":
		return install.Stop(ctx)
	case "prepare":
		return install.Prepare(ctx, os.Stdout)
	case "remove":
		return install.Remove(ctx, os.Stdout, *deleteShop)
	}
	return fmt.Errorf("setup takes check, wsl, server, counter, update, stop, prepare or remove, not %q", args[0])
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
	argv := append([]string{"wsl.exe", "-d", cfg.Distribution, "-u", "root", "-e", "/usr/local/bin/haq"}, args...)
	return exitWith(install.AsServer(context.Background(), os.Stdout, argv...))
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
