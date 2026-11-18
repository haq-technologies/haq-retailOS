// Package install is the HAQ Helper's install engine (D86): what the setup program asks, turned into a
// shop on this PC. "This PC runs the shop" imports the haq-server distribution into WSL2 under the hidden
// HAQServer account and installs the server inside it, as the spike proved (apps/windows/spike); "This PC
// is a counter" trusts the shop's certificate, starts the print bridge and opens the counter. The setup
// program only asks the questions and runs this.
package install

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"os"
	"regexp"
	"strings"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// Answers are what the setup program asked. They come in a file the engine reads once and deletes at
// once, since it holds the GHCR token and the owner's PIN.
type Answers struct {
	Mode config.Mode `json:"mode"`
	// Address is this PC's fixed IPv4 address on the shop's network.
	Address string `json:"address"`
	// Printer is this PC's own receipt printer, as `haq-helper printers` names it, reached through the
	// print bridge; or PrinterHost is a network receipt printer's address. Neither is a counter with no
	// printer yet.
	Printer     string `json:"printer,omitempty"`
	PrinterHost string `json:"printer_host,omitempty"`

	// This PC runs the shop.
	Tag       string `json:"tag,omitempty"` // the release to install, or main before the first one
	GHCRUser  string `json:"ghcr_user,omitempty"`
	GHCRToken string `json:"ghcr_token,omitempty"`
	Shop      *Shop  `json:"shop,omitempty"`
	OwnerName string `json:"owner_name,omitempty"`
	OwnerPIN  string `json:"owner_pin,omitempty"`

	// This PC is a counter.
	Server  string `json:"server,omitempty"`  // the shop server's address
	Counter string `json:"counter,omitempty"` // which counter this PC is, such as T02
	Code    string `json:"code,omitempty"`    // the one-time code from `haq enrol T02` on the server
}

// Shop is the shop's details, from which the engine makes its profile (shop:make-profile's shape).
type Shop struct {
	Name        string `json:"name"`
	ReceiptName string `json:"receipt_name,omitempty"` // the receipt's heading, when it differs from the name
	Address     string `json:"address,omitempty"`
	Phone       string `json:"phone,omitempty"`
	Footer      string `json:"footer,omitempty"`
	// Counters is how many counters the shop has: T01 (this PC) to T19.
	Counters int `json:"counters"`
	// Drawers are the counters with a cash drawer, such as ["T01"].
	Drawers []string `json:"drawers,omitempty"`
}

var (
	tag          = regexp.MustCompile(`^([0-9]{4}\.[0-9]{1,2}\.[0-9]+|main)$`)
	githubUser   = regexp.MustCompile(`^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$`)
	token        = regexp.MustCompile(`^[A-Za-z0-9_]{20,255}$`)
	counterCode  = regexp.MustCompile(`^T[0-9]{2}$`)
	enrolCode    = regexp.MustCompile(`^[A-Za-z0-9-]{4,20}$`)
	printerHost  = regexp.MustCompile(`^[A-Za-z0-9.-]{1,64}$`)
	ownerPIN     = regexp.MustCompile(`^[0-9]{6}$`)
	errBothPrint = errors.New("give this PC's printer or a network printer's address, not both")
)

// ReadAnswers reads the setup's answers and deletes their file at once.
func ReadAnswers(path string) (Answers, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return Answers{}, err
	}
	removeErr := os.Remove(path)
	// The setup program writes UTF-8 with a byte-order mark, which JSON does not allow.
	raw = bytes.TrimPrefix(raw, []byte("\xef\xbb\xbf"))
	var a Answers
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&a); err != nil {
		return Answers{}, fmt.Errorf("the answers are not right: %w", err)
	}
	if removeErr != nil {
		return Answers{}, fmt.Errorf("could not delete the answers, which hold the token: %w", removeErr)
	}
	return a, a.Validate()
}

// Validate says what is wrong with the answers, if anything, in words for the person installing.
func (a Answers) Validate() error {
	address, err := netip.ParseAddr(a.Address)
	if err != nil || !address.Is4() {
		return fmt.Errorf("this PC's address %q is not an IPv4 address, such as 192.168.1.10", a.Address)
	}
	if a.Printer != "" && a.PrinterHost != "" {
		return errBothPrint
	}
	if a.PrinterHost != "" && !printerHost.MatchString(a.PrinterHost) {
		return fmt.Errorf("the printer's address %q is not an address", a.PrinterHost)
	}
	switch a.Mode {
	case config.Server:
		return a.validateServer()
	case config.Counter:
		return a.validateCounter(address)
	}
	return fmt.Errorf("the mode is %q: it must be server or counter", a.Mode)
}

func (a Answers) validateServer() error {
	switch {
	case !tag.MatchString(a.Tag):
		return fmt.Errorf("the release %q is not a release, such as 2026.12.1", a.Tag)
	case !githubUser.MatchString(a.GHCRUser):
		return fmt.Errorf("%q is not a GitHub user name", a.GHCRUser)
	case !token.MatchString(a.GHCRToken):
		return errors.New("the token is not a GitHub token: paste the whole of it, with no spaces")
	case a.Shop == nil:
		return errors.New("the shop's details are missing")
	case strings.TrimSpace(a.OwnerName) == "" || len([]rune(a.OwnerName)) > 60:
		return errors.New("give the owner's name, as on their tile (at most 60 letters)")
	case !ownerPIN.MatchString(a.OwnerPIN):
		return errors.New("the owner's PIN is 6 digits")
	}
	return a.Shop.validate()
}

func (s Shop) validate() error {
	switch {
	case strings.TrimSpace(s.Name) == "" || len([]rune(s.Name)) > 120:
		return errors.New("give the shop's name (at most 120 letters)")
	case len([]rune(s.ReceiptName)) > 120, len([]rune(s.Address)) > 200, len([]rune(s.Phone)) > 60, len([]rune(s.Footer)) > 200:
		return errors.New("a receipt line is too long")
	case s.Counters < 1 || s.Counters > 19:
		return errors.New("a shop has 1 to 19 counters")
	}
	for _, d := range s.Drawers {
		n := 0
		if _, err := fmt.Sscanf(d, "T%02d", &n); err != nil || !counterCode.MatchString(d) || n < 1 || n > s.Counters {
			return fmt.Errorf("%q has a drawer, but the shop's counters are T01 to T%02d", d, s.Counters)
		}
	}
	return nil
}

func (a Answers) validateCounter(address netip.Addr) error {
	server, err := netip.ParseAddr(a.Server)
	switch {
	case err != nil || !server.Is4():
		return fmt.Errorf("the server's address %q is not an IPv4 address, such as 192.168.1.10", a.Server)
	case server == address:
		return errors.New("this PC is the server's address: run the setup as \"This PC runs the shop\" instead")
	case !counterCode.MatchString(a.Counter) || a.Counter == "T00":
		return fmt.Errorf("%q is not a counter, such as T02", a.Counter)
	case !enrolCode.MatchString(a.Code):
		return errors.New("type the one-time code from `haq enrol` on the server, such as 4827-1093")
	}
	return nil
}

// Profile is the shop's profile for install.sh: its counters T01 (this PC) onwards, the owner's phone as
// D01, and the receipt. Only this PC's counter has its printer now; each other counter's is set when that
// PC is installed (`haq printer T02 <its address>`).
func (a Answers) Profile() ([]byte, error) {
	if a.Mode != config.Server || a.Shop == nil {
		return nil, errors.New("only the PC that runs the shop has a profile")
	}
	type printer struct {
		Host string `json:"host"`
		Port int    `json:"port"`
	}
	type terminal struct {
		Code    string   `json:"code"`
		Name    string   `json:"name"`
		Drawer  bool     `json:"drawer"`
		Kind    string   `json:"kind"`
		Printer *printer `json:"printer,omitempty"`
	}
	drawer := map[string]bool{}
	for _, d := range a.Shop.Drawers {
		drawer[d] = true
	}
	var terminals []terminal
	for n := 1; n <= a.Shop.Counters; n++ {
		t := terminal{Code: fmt.Sprintf("T%02d", n), Name: fmt.Sprintf("Counter %d", n), Kind: "counter"}
		t.Drawer = drawer[t.Code]
		if n == 1 {
			switch {
			case a.Printer != "":
				t.Printer = &printer{Host: a.Address, Port: 9100} // the bridge on this PC
			case a.PrinterHost != "":
				t.Printer = &printer{Host: a.PrinterHost, Port: 9100}
			}
		}
		terminals = append(terminals, t)
	}
	terminals = append(terminals, terminal{Code: "D01", Name: "Owner's phone", Kind: "back_office"})
	receiptName := a.Shop.ReceiptName
	if strings.TrimSpace(receiptName) == "" {
		receiptName = a.Shop.Name
	}
	profile := map[string]any{
		"schema_version": 1,
		"shop":           map[string]string{"name": strings.TrimSpace(a.Shop.Name)},
		"branch":         map[string]string{"code": "B01", "name": "Main branch"},
		"terminals":      terminals,
		"receipt": map[string]string{
			"shop_name": strings.TrimSpace(receiptName), "address": strings.TrimSpace(a.Shop.Address),
			"phone": strings.TrimSpace(a.Shop.Phone), "footer": strings.TrimSpace(a.Shop.Footer),
		},
		"settings": map[string]any{},
	}
	// As typed: a receipt's "&" stays "&", not JSON's HTML-safe \u0026.
	var out bytes.Buffer
	enc := json.NewEncoder(&out)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", "  ")
	if err := enc.Encode(profile); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}
