package install

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

func serverAnswers() Answers {
	return Answers{
		Mode: config.Server, Address: "10.92.139.200", Printer: "XP-80C",
		Tag: "2026.12.1", GHCRUser: "techabdu", GHCRToken: "ghp_" + strings.Repeat("a", 36),
		Shop: &Shop{Name: "Ade Phones", Address: "12 Wuse Market", Phone: "0803 000 0000",
			Footer: "Goods sold in good condition.", Counters: 3, Drawers: []string{"T01", "T03"}},
		OwnerName: "Ade", OwnerPIN: "135790",
	}
}

func TestTheAnswersFileIsDeletedOnceRead(t *testing.T) {
	path := filepath.Join(t.TempDir(), "answers.json")
	raw, _ := json.Marshal(serverAnswers())
	if err := os.WriteFile(path, raw, 0o600); err != nil {
		t.Fatal(err)
	}
	a, err := ReadAnswers(path)
	if err != nil {
		t.Fatal(err)
	}
	if a.GHCRToken == "" || a.Shop.Counters != 3 {
		t.Fatalf("read %+v", a)
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatal("the file with the token is still there")
	}
}

func TestBadAnswersAreRefusedInWordsForTheInstaller(t *testing.T) {
	for name, tc := range map[string]struct {
		change func(*Answers)
		want   string
	}{
		"an address that is a name": {func(a *Answers) { a.Address = "haq-server" }, "not an IPv4"},
		"two printers":              {func(a *Answers) { a.PrinterHost = "192.168.1.21" }, "not both"},
		"no release":                {func(a *Answers) { a.Tag = "latest" }, "not a release"},
		"a token with a space":      {func(a *Answers) { a.GHCRToken = "ghp_abc def" }, "whole of it"},
		"a short owner PIN":         {func(a *Answers) { a.OwnerPIN = "2468" }, "6 digits"},
		"no counters":               {func(a *Answers) { a.Shop.Counters = 0 }, "1 to 19"},
		"a drawer on no counter":    {func(a *Answers) { a.Shop.Drawers = []string{"T04"} }, "T01 to T03"},
		"no owner":                  {func(a *Answers) { a.OwnerName = " " }, "owner's name"},
		"a counter on the server's address": {func(a *Answers) {
			a.Mode, a.Server, a.Counter, a.Code = config.Counter, a.Address, "T02", "4827-1093"
		}, "runs the shop"},
		"a counter with no code": {func(a *Answers) {
			a.Mode, a.Address, a.Server, a.Counter, a.Code = config.Counter, "10.92.139.201", "10.92.139.200", "T02", ""
		}, "one-time code"},
		"a counter with no thumbprint": {func(a *Answers) {
			a.Mode, a.Address, a.Server, a.Counter, a.Code = config.Counter, "10.92.139.201", "10.92.139.200", "T02", "4827-1093"
		}, "first 16 characters"},
		"a counter with too little of the thumbprint": {func(a *Answers) {
			a.Mode, a.Address, a.Server, a.Counter, a.Code, a.Thumbprint = config.Counter, "10.92.139.201", "10.92.139.200", "T02", "4827-1093", "33CB C863"
		}, "first 16 characters"},
	} {
		t.Run(name, func(t *testing.T) {
			a := serverAnswers()
			tc.change(&a)
			if err := a.Validate(); err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("got %v, want an error about %q", err, tc.want)
			}
		})
	}
}

func TestACounterNeedsNoShopDetails(t *testing.T) {
	a := Answers{Mode: config.Counter, Address: "10.92.139.201", Server: "10.92.139.200", Counter: "T02",
		Code: "4827-1093", Thumbprint: "33cb c863 ec7d 14d0", Printer: "XP-58"}
	if err := a.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestTheProfileIsTheOneInstallShTakes(t *testing.T) {
	raw, err := serverAnswers().Profile()
	if err != nil {
		t.Fatal(err)
	}
	var p struct {
		SchemaVersion int `json:"schema_version"`
		Shop          struct{ Name string }
		Branch        struct{ Code, Name string }
		Terminals     []struct {
			Code, Name, Kind string
			Drawer           bool
			Printer          *struct {
				Host string
				Port int
			}
		}
		Receipt  map[string]string
		Settings map[string]any
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		t.Fatal(err)
	}
	if p.SchemaVersion != 1 || p.Shop.Name != "Ade Phones" || p.Branch.Code != "B01" || p.Settings == nil {
		t.Fatalf("got %s", raw)
	}
	codes := []string{}
	for _, term := range p.Terminals {
		codes = append(codes, term.Code)
	}
	if strings.Join(codes, ",") != "T01,T02,T03,D01" {
		t.Fatalf("terminals %v", codes)
	}
	t01, t02, t03, d01 := p.Terminals[0], p.Terminals[1], p.Terminals[2], p.Terminals[3]
	// T01 prints through this PC's bridge, at this PC's address.
	if t01.Printer == nil || t01.Printer.Host != "10.92.139.200" || t01.Printer.Port != 9100 || !t01.Drawer {
		t.Fatalf("T01 %+v", t01)
	}
	if t02.Printer != nil || t02.Drawer || !t03.Drawer {
		t.Fatalf("T02 %+v, T03 %+v", t02, t03)
	}
	if d01.Kind != "back_office" || d01.Drawer || d01.Printer != nil {
		t.Fatalf("D01 %+v", d01)
	}
	if p.Receipt["shop_name"] != "Ade Phones" || p.Receipt["footer"] != "Goods sold in good condition." {
		t.Fatalf("receipt %v", p.Receipt)
	}
}

func TestANetworkPrinterGoesInTheProfileAsItIs(t *testing.T) {
	a := serverAnswers()
	a.Printer, a.PrinterHost, a.Shop.ReceiptName = "", "192.168.1.21", "ADE PHONES & ACCESSORIES"
	raw, err := a.Profile()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(raw), `"host": "192.168.1.21"`) || !strings.Contains(string(raw), `"shop_name": "ADE PHONES & ACCESSORIES"`) {
		t.Fatalf("got %s", raw)
	}
}

func TestAnswersWithAByteOrderMarkAreRead(t *testing.T) {
	path := filepath.Join(t.TempDir(), "answers.json")
	raw, _ := json.Marshal(Answers{Mode: config.Counter, Address: "10.92.139.201", Server: "10.92.139.200", Counter: "T02", Code: "4827-1093",
		Thumbprint: "33CB C863 EC7D 14D0"})
	if err := os.WriteFile(path, append([]byte("\xef\xbb\xbf"), raw...), 0o600); err != nil {
		t.Fatal(err)
	}
	if a, err := ReadAnswers(path); err != nil || a.Counter != "T02" {
		t.Fatalf("got %+v, %v", a, err)
	}
}
