package install

import "testing"

func TestANewAddressIsAPrivateIPv4Address(t *testing.T) {
	for address, ok := range map[string]bool{
		"192.168.1.20":  true,
		"10.0.0.5":      true,
		"172.16.4.9":    true,
		"172.31.255.1":  true,
		" 192.168.0.7 ": true,
		"172.32.0.1":    false, // past 172.31
		"8.8.8.8":       false,
		"fd00::1":       false, // IPv6
		"192.168.1":     false,
		"haq-server":    false,
		"":              false,
	} {
		if err := privateIPv4(address); (err == nil) != ok {
			t.Errorf("privateIPv4(%q) = %v, want ok %v", address, err, ok)
		}
	}
}
