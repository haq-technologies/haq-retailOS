package install

import (
	"fmt"
	"net/netip"
	"strings"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/wslnet"
)

// hasAddress says whether this PC has the address on one of its adapters: the server's certificate and the
// counters are made for it, so it must be the PC's own, and fixed.
func hasAddress(address string) error {
	want, err := netip.ParseAddr(address)
	if err != nil {
		return err
	}
	adapters, err := wslnet.Adapters()
	if err != nil {
		return err
	}
	return addressIn(adapters, want)
}

func addressIn(adapters []wslnet.Adapter, want netip.Addr) error {
	var have []string
	for _, a := range adapters {
		if wslnet.IsWSL(a.Name) {
			continue
		}
		for _, p := range a.Prefixes {
			if p.Addr() == want {
				return nil
			}
			if p.Addr().Is4() && !p.Addr().IsLoopback() && !p.Addr().IsLinkLocalUnicast() {
				have = append(have, fmt.Sprintf("%s on %s", p.Addr(), a.Name))
			}
		}
	}
	return fmt.Errorf("this PC does not have the address %s; it has %v. Give it that fixed address first", want, have)
}

// Addresses are this PC's own IPv4 addresses on its networks, not WSL's or the loopback, for the setup
// program to offer as this PC's fixed address.
func Addresses() ([]string, error) {
	adapters, err := wslnet.Adapters()
	if err != nil {
		return nil, err
	}
	return ownAddresses(adapters), nil
}

func ownAddresses(adapters []wslnet.Adapter) []string {
	var list []string
	for _, a := range adapters {
		if wslnet.IsWSL(a.Name) || strings.HasPrefix(a.Name, "vEthernet") {
			continue
		}
		for _, p := range a.Prefixes {
			if ip := p.Addr(); ip.Is4() && !ip.IsLoopback() && !ip.IsLinkLocalUnicast() {
				list = append(list, ip.String())
			}
		}
	}
	return list
}

// privateIPv4 says whether address is an IPv4 address on a private network (10/8, 172.16/12, 192.168/16):
// the only kind the shop's certificate authority vouches for, so a server can move to it with the same
// authority (D140).
func privateIPv4(address string) error {
	a, err := netip.ParseAddr(strings.TrimSpace(address))
	if err != nil || !a.Is4() {
		return fmt.Errorf("type an IPv4 address such as 192.168.1.20, not %q", address)
	}
	if !a.IsPrivate() {
		return fmt.Errorf("%s is not a private address (10.x.x.x, 172.16.x.x to 172.31.x.x or 192.168.x.x), which the shop's certificate vouches for", a)
	}
	return nil
}
