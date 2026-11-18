// Command haq-helper is the HAQ Helper, the Windows side of HAQ Retail OS (D86 to D88):
//
//	haq-helper service [run|install|start|stop|remove|state]   the HAQ Helper service: the print bridge for
//	                                                         this PC's receipt printer, and the PC's report
//	haq-helper keepalive                                     keeps the hidden server running (as HAQServer)
//	haq-helper health [--write]                              the PC's disks and clock, as the server reads them
//	haq-helper drives                                        the drives, and which are off-site drives
//	haq-helper printers [--json]                             the printers on this PC, and how each is reached
//	haq-helper test-print --printer <name>                   the test receipt, with its barcode and QR code
//	haq-helper bridge --printer <name> [--listen :9100] [--allow 192.168.1.10,172.24.16.0/20]
//	haq-helper version
//
// The service and the keep-alive read the setup's settings, C:\ProgramData\HAQ\helper.json, and log to
// C:\ProgramData\HAQ\logs. A printer is named as `printers` lists it: its Windows name, its port (USB001,
// COM3) or its target. Anywhere, file:<path> stands in for a printer.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"net/netip"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"text/tabwriter"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/bridge"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/drives"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/escpos"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/health"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/install"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/keepalive"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/logfile"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/printer"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/service"
)

// logMax keeps each log under 5 MB, plus one older file.
const logMax = 5 << 20

// version is set when the release builds it: -ldflags "-X main.version=2026.12.1".
var version = "development"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "setup":
		err = runSetup(os.Args[2:])
	case "server":
		err = runServerCommand(os.Args[2:])
	case "as-server":
		err = runAsServer(os.Args[2:])
	case "run-queued":
		os.Exit(install.RunQueued(install.RunnerDir()))
	case "service":
		err = runService(os.Args[2:])
	case "keepalive":
		err = runKeepalive()
	case "health":
		err = printHealth(os.Args[2:])
	case "drives":
		err = listDrives()
	case "addresses":
		err = listAddresses()
	case "printers":
		err = printers(os.Args[2:])
	case "test-print":
		err = testPrint(os.Args[2:])
	case "bridge":
		err = runBridge(os.Args[2:])
	case "version":
		fmt.Println("HAQ Helper", version)
	case "help", "-h", "--help":
		usage()
	default:
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n", os.Args[1])
		usage()
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprint(os.Stderr, `Usage:
  haq-helper setup check [--mode server|counter] [--address <this PC's address>]
  haq-helper setup wsl                     turns on Windows' Linux; Windows restarts after it
  haq-helper setup server --answers <file> --distro <haq-server.tar.gz> [--result <file>]
  haq-helper setup counter --answers <file> [--result <file>]
  haq-helper setup update | stop | prepare
  haq-helper setup remove [--delete-shop-and-sales]
  haq-helper server <haq command>          the shop server's own commands: enrol T02, printer T02 <address>,
                                           certificate, check, update, users ...
  haq-helper as-server <program> [args]    runs a program as HAQServer, which owns the server's Linux
  haq-helper service [run|install|start|stop|remove|state]
  haq-helper keepalive                     keeps the hidden server running; run by its task as HAQServer
  haq-helper health [--write]              the PC's disks and clock, as the server's health page reads them
  haq-helper drives                        the drives, and which are off-site drives
  haq-helper addresses                     this PC's own addresses, for its fixed address
  haq-helper printers [--json|--names]             the printers on this PC, and how each is reached
  haq-helper test-print --printer <name>   the test receipt, with its barcode and QR code
  haq-helper bridge --printer <name> [--listen :9100] [--allow <address or network>,...]
  haq-helper version
`)
}

// runService runs the service when Windows starts it, or by hand in a console until Ctrl+C; and installs,
// starts, stops and removes it for the setup.
func runService(args []string) error {
	what := "run"
	if len(args) > 0 {
		what = args[0]
	}
	switch what {
	case "run":
		if service.IsService() {
			return service.Run(func(ctx context.Context) error {
				l, err := logfile.Open(filepath.Join(config.LogDir(), "service.log"), logMax)
				if err != nil {
					return err
				}
				defer l.Close()
				return serve(ctx, log.New(l, "", log.LstdFlags).Printf)
			})
		}
		ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
		defer stop()
		return serve(ctx, log.New(os.Stderr, "", log.LstdFlags).Printf)
	case "install":
		exe, err := os.Executable()
		if err != nil {
			return err
		}
		return service.Install(exe, "service")
	case "start":
		return service.Start()
	case "stop":
		return service.Stop()
	case "remove":
		return service.Remove()
	case "state":
		state, err := service.State()
		if err == nil {
			fmt.Println(state)
		}
		return err
	}
	return fmt.Errorf("service takes run, install, start, stop, remove or state, not %q", what)
}

// runKeepalive keeps the hidden server running, until it is stopped.
func runKeepalive() error {
	cfg, err := config.Load(config.Path())
	if err != nil {
		return err
	}
	if cfg.Mode != config.Server {
		return errors.New("this PC is a counter: the server runs on another PC")
	}
	l, err := logfile.Open(filepath.Join(config.LogDir(), "keepalive.log"), logMax)
	if err != nil {
		return err
	}
	defer l.Close()
	logger := log.New(l, "", log.LstdFlags)
	logger.Printf("HAQ Helper %s: keeping %s running", version, cfg.Distribution)
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	keepalive.Run(ctx, keepalive.Config{
		Distribution: cfg.Distribution, StatusDir: config.StatusDir(), Drives: drives.List, Logf: logger.Printf,
	})
	return nil
}

func printHealth(args []string) error {
	fs := flag.NewFlagSet("health", flag.ExitOnError)
	write := fs.Bool("write", false, "also write it where the keep-alive passes it to the server")
	_ = fs.Parse(args)
	r := health.Collect(context.Background())
	if *write {
		if err := health.Write(config.StatusDir(), r); err != nil {
			return err
		}
	}
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	return enc.Encode(r)
}

func listAddresses() error {
	list, err := install.Addresses()
	if err != nil {
		return err
	}
	for _, a := range list {
		fmt.Println(a)
	}
	return nil
}

func listDrives() error {
	list, err := drives.List()
	if err != nil {
		return err
	}
	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "DRIVE\tLABEL\tOFF-SITE DRIVE")
	for _, d := range list {
		which, ok := drives.Which(d.Label)
		if !ok {
			which = "-"
		}
		fmt.Fprintf(w, "%s\t%s\t%s\n", d.Letter, d.Label, which)
	}
	return w.Flush()
}

func printers(args []string) error {
	fs := flag.NewFlagSet("printers", flag.ExitOnError)
	asJSON := fs.Bool("json", false, "the list as JSON")
	names := fs.Bool("names", false, "the receipt printers there now, a name a line, for the setup program")
	_ = fs.Parse(args)
	list, err := printer.List()
	if err != nil {
		return err
	}
	if *names {
		for _, f := range list {
			if f.Present && !printer.Virtual(f) {
				fmt.Println(f.Name)
			}
		}
		return nil
	}
	if *asJSON {
		if list == nil {
			list = []printer.Found{}
		}
		enc := json.NewEncoder(os.Stdout)
		enc.SetIndent("", "  ")
		return enc.Encode(list)
	}
	if len(list) == 0 {
		fmt.Println("No printers on this PC. Plug the receipt printer in and switch it on, then look again.")
		return nil
	}
	w := tabwriter.NewWriter(os.Stdout, 0, 0, 2, ' ', 0)
	fmt.Fprintln(w, "PRINTER\tPORT\tREACHED THROUGH\tTHERE NOW")
	for _, f := range list {
		fmt.Fprintf(w, "%s\t%s\t%s\t%s\n", f.Name, f.Port, describe(f.Kind), yesNo(f.Present))
	}
	return w.Flush()
}

func describe(k printer.Kind) string {
	switch k {
	case printer.KindUSB:
		return "USB printing device (answers paper out)"
	case printer.KindCOM:
		return "COM port (answers paper out)"
	case printer.KindQueue:
		return "print queue (RAW; status from Windows)"
	}
	return string(k)
}

func yesNo(b bool) string {
	if b {
		return "yes"
	}
	return "no"
}

func choose(fs *flag.FlagSet, args []string) (printer.Printer, error) {
	name := fs.String("printer", "", "the printer, as `haq-helper printers` lists it, or file:<path>")
	_ = fs.Parse(args)
	if strings.TrimSpace(*name) == "" {
		return nil, errors.New("give the printer: --printer <name>, as haq-helper printers lists it")
	}
	return printer.Choose(*name)
}

func testPrint(args []string) error {
	fs := flag.NewFlagSet("test-print", flag.ExitOnError)
	p, err := choose(fs, args)
	if err != nil {
		return err
	}
	if !p.Present() {
		return fmt.Errorf("%s is not there now: plug it in and switch it on", p.Name())
	}
	b := bridge.New(bridge.Config{Printer: p})
	receipt := escpos.TestReceipt([]string{
		"HAQ Retail OS",
		"Test receipt",
		"",
		ascii(p.Name()),
		time.Now().Format("2 January 2006 15:04"),
		"",
		"This printer works.",
		"Scan the codes below for the scanner check.",
	})
	if err := b.Print(receipt); err != nil {
		return fmt.Errorf("the test receipt did not print: %w", err)
	}
	fmt.Println("The test receipt went to", p.Name()+". The scanner check reads:", escpos.ScanCheck)
	return nil
}

// ascii keeps a printer's name printable on a receipt: anything else becomes '?'.
func ascii(s string) string {
	out := []rune(s)
	for i, r := range out {
		if r < 0x20 || r > 0x7e {
			out[i] = '?'
		}
	}
	return string(out)
}

func runBridge(args []string) error {
	fs := flag.NewFlagSet("bridge", flag.ExitOnError)
	listen := fs.String("listen", ":9100", "where the shop server reaches it")
	allow := fs.String("allow", "", "the shop server's addresses or networks, separated by commas; empty lets in whoever the firewall lets in")
	p, err := choose(fs, args)
	if err != nil {
		return err
	}
	var prefixes []netip.Prefix
	for _, a := range strings.Split(*allow, ",") {
		if a = strings.TrimSpace(a); a == "" {
			continue
		}
		if prefix, err := netip.ParsePrefix(a); err == nil {
			prefixes = append(prefixes, prefix.Masked())
			continue
		}
		ip, err := netip.ParseAddr(a)
		if err != nil {
			return fmt.Errorf("%q is not an address or a network", a)
		}
		prefixes = append(prefixes, netip.PrefixFrom(ip, ip.BitLen()))
	}
	var only bridge.Allow
	if len(prefixes) > 0 {
		only = bridge.Only(prefixes...)
	}
	logger := log.New(os.Stderr, "", log.LstdFlags)
	b := bridge.New(bridge.Config{Listen: *listen, Printer: p, Allow: only, Logf: logger.Printf})
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	if !p.Present() {
		logger.Printf("%s is not there yet: the bridge listens once it is", p.Name())
	}
	return b.Run(ctx)
}
