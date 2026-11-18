// Package wslnet finds WSL's own network on the PC that runs the shop server (D86). The server inside WSL
// reaches the print bridge from that network, whose addresses WSL chooses each time it starts, so the bridge
// on that PC lets in that network and nothing else on the shop's network.
package wslnet

import (
	"net/netip"
	"strings"
	"sync"
	"time"
)

// Adapter is one network adapter's name and its addresses with their networks.
type Adapter struct {
	Name     string
	Prefixes []netip.Prefix
}

// IsWSL says whether an adapter is WSL's: Windows names it "vEthernet (WSL)", or on Windows 11
// "vEthernet (WSL (Hyper-V firewall))".
func IsWSL(name string) bool { return strings.HasPrefix(name, "vEthernet (WSL") }

// Networks are the WSL adapters' IPv4 networks.
func Networks(adapters []Adapter) []netip.Prefix {
	var nets []netip.Prefix
	for _, a := range adapters {
		if !IsWSL(a.Name) {
			continue
		}
		for _, p := range a.Prefixes {
			if p.Addr().Is4() {
				nets = append(nets, p.Masked())
			}
		}
	}
	return nets
}

// retry is the least time between two looks for an address it does not know.
var retry = time.Second

// Allow lets in WSL's networks as they are now, looking again at most every few seconds, so a server whose
// network changed when WSL restarted is let in at its first job.
func Allow(list func() ([]Adapter, error), every time.Duration) func(netip.Addr) bool {
	var (
		mu   sync.Mutex
		nets []netip.Prefix
		at   time.Time
	)
	return func(ip netip.Addr) bool {
		mu.Lock()
		defer mu.Unlock()
		if at.IsZero() || time.Since(at) > every || (!contains(nets, ip) && time.Since(at) >= retry) {
			// An address it does not know yet may be a network WSL has just made: look again, at once, but
			// at most once a second, so others on the shop's network cannot make it look all the time.
			if adapters, err := list(); err == nil {
				nets, at = Networks(adapters), time.Now()
			}
		}
		return contains(nets, ip)
	}
}

func contains(nets []netip.Prefix, ip netip.Addr) bool {
	for _, n := range nets {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}
