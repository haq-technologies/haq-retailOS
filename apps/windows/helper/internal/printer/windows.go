//go:build windows

package printer

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

var (
	usbPort = regexp.MustCompile(`(?i)^USB(\d+)$`)
	comPort = regexp.MustCompile(`(?i)^(COM\d+):?$`)
)

// List finds every Windows print queue, each reached the best way it can be, and every USB printing device
// that has no print queue of its own (a printer plugged in with no driver installed).
func List() ([]Found, error) {
	queues, err := enumPrinters()
	if err != nil {
		return nil, err
	}
	devices := usbDevices()
	used := map[string]bool{}
	found := make([]Found, 0, len(queues)+len(devices))
	for _, q := range queues {
		f := Found{Name: q.name, Port: q.port, Kind: KindQueue, Target: q.name, Present: q.present()}
		if m := usbPort.FindStringSubmatch(q.port); m != nil {
			n, _ := strconv.Atoi(m[1])
			for _, d := range devices {
				if d.port == n {
					f.Kind, f.Target, f.Present = KindUSB, d.path, true
					used[d.path] = true
				}
			}
		} else if m := comPort.FindStringSubmatch(q.port); m != nil {
			f.Kind, f.Target = KindCOM, strings.ToUpper(m[1])
			f.Present = comPresent(f.Target)
		}
		found = append(found, f)
	}
	for _, d := range devices {
		if used[d.path] {
			continue
		}
		name := d.description
		if name == "" {
			name = "USB printer"
		}
		port := ""
		if d.port > 0 {
			port = fmt.Sprintf("USB%03d", d.port)
			name += " on " + port
		}
		found = append(found, Found{Name: name, Kind: KindUSB, Target: d.path, Port: port, Present: true})
	}
	return found, nil
}

// winPrinter is a printer chosen by its name, its port or its target, found again each time it is used,
// so one unplugged and plugged back, perhaps on another USB port, is found where it now is.
type winPrinter struct{ spec string }

func find(spec string) (Printer, error) {
	if strings.TrimSpace(spec) == "" {
		return nil, ErrNotFound
	}
	return winPrinter{spec: spec}, nil
}

func (p winPrinter) resolve() (Found, bool) {
	list, err := List()
	if err != nil {
		return Found{}, false
	}
	for _, f := range list {
		if strings.EqualFold(f.Name, p.spec) || strings.EqualFold(f.Port, p.spec) || strings.EqualFold(f.Target, p.spec) {
			return f, true
		}
	}
	return Found{}, false
}

func (p winPrinter) Name() string { return p.spec }

func (p winPrinter) Present() bool {
	f, ok := p.resolve()
	return ok && f.Present
}

func (p winPrinter) Open() (Device, error) {
	f, ok := p.resolve()
	if !ok {
		return nil, ErrNotFound
	}
	switch f.Kind {
	case KindUSB:
		return openUSB(f.Target)
	case KindCOM:
		return openCOM(f.Target)
	default:
		return openQueue(f.Target)
	}
}
