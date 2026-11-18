//go:build windows

package wslnet

import (
	"errors"
	"net/netip"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Adapters lists this PC's network adapters with their IPv4 addresses.
func Adapters() ([]Adapter, error) {
	size := uint32(16 << 10)
	for range 4 {
		buf := make([]byte, size)
		head := (*windows.IpAdapterAddresses)(unsafe.Pointer(&buf[0]))
		flags := uint32(windows.GAA_FLAG_SKIP_ANYCAST | windows.GAA_FLAG_SKIP_MULTICAST | windows.GAA_FLAG_SKIP_DNS_SERVER)
		err := windows.GetAdaptersAddresses(windows.AF_INET, flags, 0, head, &size)
		if errors.Is(err, windows.ERROR_BUFFER_OVERFLOW) {
			continue
		}
		if err != nil {
			return nil, err
		}
		var list []Adapter
		for a := head; a != nil; a = a.Next {
			adapter := Adapter{Name: windows.UTF16PtrToString(a.FriendlyName)}
			for u := a.FirstUnicastAddress; u != nil; u = u.Next {
				ip, ok := netip.AddrFromSlice(u.Address.IP())
				if !ok {
					continue
				}
				adapter.Prefixes = append(adapter.Prefixes, netip.PrefixFrom(ip.Unmap(), int(u.OnLinkPrefixLength)))
			}
			list = append(list, adapter)
		}
		return list, nil
	}
	return nil, errors.New("the adapter list kept growing")
}
