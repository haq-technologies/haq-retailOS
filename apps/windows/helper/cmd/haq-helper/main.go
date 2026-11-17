// Command haq-helper is the HAQ Helper, the Windows side of HAQ Retail OS (D86 to D88). For now it is the
// print bridge (D87) and what the setup needs to choose a printer:
//
//	haq-helper printers [--json]                         the printers on this PC, and how each is reached
//	haq-helper test-print --printer <name>               the test receipt, with its barcode and QR code
//	haq-helper bridge --printer <name> [--listen :9100] [--allow 192.168.1.10]
//	haq-helper version
//
// A printer is named as `printers` lists it: its Windows name, its port (USB001, COM3) or its target.
// Anywhere, file:<path> stands in for a printer.
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
	"strings"
	"text/tabwriter"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/bridge"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/escpos"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/printer"
)

// version is set when the release builds it: -ldflags "-X main.version=2026.12.1".
var version = "development"

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
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
  haq-helper printers [--json]             the printers on this PC, and how each is reached
  haq-helper test-print --printer <name>   the test receipt, with its barcode and QR code
  haq-helper bridge --printer <name> [--listen :9100] [--allow <server address>,...]
  haq-helper version
`)
}

func printers(args []string) error {
	fs := flag.NewFlagSet("printers", flag.ExitOnError)
	asJSON := fs.Bool("json", false, "the list as JSON, for the setup")
	_ = fs.Parse(args)
	list, err := printer.List()
	if err != nil {
		return err
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
	allow := fs.String("allow", "", "the shop server's addresses, separated by commas; empty lets in whoever the firewall lets in")
	p, err := choose(fs, args)
	if err != nil {
		return err
	}
	var allowed []netip.Addr
	for _, a := range strings.Split(*allow, ",") {
		if a = strings.TrimSpace(a); a == "" {
			continue
		}
		ip, err := netip.ParseAddr(a)
		if err != nil {
			return fmt.Errorf("%q is not an address", a)
		}
		allowed = append(allowed, ip)
	}
	logger := log.New(os.Stderr, "", log.LstdFlags)
	b := bridge.New(bridge.Config{Listen: *listen, Printer: p, Allow: allowed, Logf: logger.Printf})
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()
	if !p.Present() {
		logger.Printf("%s is not there yet: the bridge listens once it is", p.Name())
	}
	return b.Run(ctx)
}
