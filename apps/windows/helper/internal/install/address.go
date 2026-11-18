package install

import (
	"fmt"
	"net/netip"

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
