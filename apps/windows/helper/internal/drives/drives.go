// Package drives notices the off-site flash drives (spec section 9) [S16, S17] on a Windows PC that runs the
// shop server (D86). On the mini-PC, udev starts the copy when a drive labelled HAQ-OFFSITE-A or
// HAQ-OFFSITE-B is plugged in; here the keep-alive looks for one every few seconds, and starts the server's
// own copy, deploy/offsite.sh copy-windows, once for each time a drive is plugged in.
package drives

import "strings"

// Drive is a drive Windows has given a letter, with its volume's label and serial number.
type Drive struct {
	Letter string // "E:"
	Label  string
	Serial uint32
}

// Which says which off-site drive a label is, A or B, if it is one.
func Which(label string) (string, bool) {
	switch strings.ToUpper(strings.TrimSpace(label)) {
	case "HAQ-OFFSITE-A":
		return "A", true
	case "HAQ-OFFSITE-B":
		return "B", true
	}
	return "", false
}

// Watcher remembers the off-site drives it has seen, so each is copied to once for each time it is plugged in.
type Watcher struct {
	seen map[Drive]bool
}

// Plugged takes the drives there now and returns the off-site drives that were not there last time,
// including those already plugged in when the PC started, as udev does on the mini-PC.
func (w *Watcher) Plugged(now []Drive) []Drive {
	there := make(map[Drive]bool, len(now))
	var plugged []Drive
	for _, d := range now {
		if _, ok := Which(d.Label); !ok {
			continue
		}
		there[d] = true
		if !w.seen[d] {
			plugged = append(plugged, d)
		}
	}
	w.seen = there
	return plugged
}
