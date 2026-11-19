package install

import (
	"net/netip"
	"strings"
	"testing"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/wslnet"
)

var pc = []wslnet.Adapter{
	{Name: "Wi-Fi", Prefixes: []netip.Prefix{netip.MustParsePrefix("10.92.139.200/24"), netip.MustParsePrefix("169.254.3.9/16")}},
	{Name: "vEthernet (WSL (Hyper-V firewall))", Prefixes: []netip.Prefix{netip.MustParsePrefix("172.24.16.1/20")}},
	{Name: "vEthernet (Default Switch)", Prefixes: []netip.Prefix{netip.MustParsePrefix("172.30.0.1/20")}},
	{Name: "Loopback Pseudo-Interface 1", Prefixes: []netip.Prefix{netip.MustParsePrefix("127.0.0.1/8")}},
}

func TestThePCsOwnAddressesAreOffered(t *testing.T) {
	if got := ownAddresses(pc); strings.Join(got, ",") != "10.92.139.200" {
		t.Fatalf("got %v", got)
	}
}

func TestAnAddressThePCDoesNotHaveIsRefusedWithTheOnesItHas(t *testing.T) {
	if err := addressIn(pc, netip.MustParseAddr("10.92.139.200")); err != nil {
		t.Fatal(err)
	}
	err := addressIn(pc, netip.MustParseAddr("192.168.1.10"))
	if err == nil || !strings.Contains(err.Error(), "10.92.139.200 on Wi-Fi") {
		t.Fatalf("got %v", err)
	}
	// WSL's own address is never this PC's fixed address.
	if err := addressIn(pc, netip.MustParseAddr("172.24.16.1")); err == nil {
		t.Fatal("WSL's address was taken as the PC's")
	}
}
