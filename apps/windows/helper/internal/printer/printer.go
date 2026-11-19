// Package printer reaches a receipt printer on this PC for the print bridge (D87). On Windows a printer is
// reached, best first, through its USB printing device or its USB virtual COM port, which both answer the
// status query, or else through its Windows print queue in RAW mode, which only takes bytes. Anywhere, a
// file stands in for a printer, for development and tests.
package printer

import (
	"errors"
	"os"
	"strings"
	"time"
)

// Printer is one receipt printer, found but not open.
type Printer interface {
	// Name is what the setup shows: the Windows printer's name, or the file's path.
	Name() string
	// Present says whether the printer is there now: plugged in and switched on, as far as Windows knows.
	Present() bool
	// Open opens it for one job or one status query.
	Open() (Device, error)
}

// Device is an open printer. What is written goes to the printer as it is.
type Device interface {
	Write(p []byte) (int, error)
	// Close ends the job; a print queue prints it then.
	Close() error
}

// TwoWay is a device that answers, as a printer on its USB printing device or COM port does.
type TwoWay interface {
	Device
	// ReadTimeout reads the printer's answer, waiting at most timeout; 0 bytes and no error when it gave none.
	ReadTimeout(p []byte, timeout time.Duration) (int, error)
}

// Queued is a device reached only through its Windows print queue, which cannot ask the printer itself:
// the bridge answers the status query from the queue's state instead.
type Queued interface {
	Device
	State() (paperOut, coverOpen bool)
}

// Kind says how a printer is reached.
type Kind string

const (
	KindUSB   Kind = "usb"   // the Windows USB printing device: two-way
	KindCOM   Kind = "com"   // a USB virtual COM port: two-way
	KindQueue Kind = "queue" // the Windows print queue, RAW: one-way
	KindFile  Kind = "file"  // a file, for development and tests
)

// Found is one printer in the list the setup shows.
type Found struct {
	Name string `json:"name"`
	Kind Kind   `json:"kind"`
	// Target is what Open needs: a device path, a COM port, a print queue's name or a file's path.
	Target string `json:"target"`
	// Port is the Windows port the printer is on, such as USB001 or COM3, when it has one.
	Port    string `json:"port,omitempty"`
	Present bool   `json:"present"`
}

// ErrNotFound means the named printer is not on this PC.
var ErrNotFound = errors.New("that printer is not on this PC")

// Choose finds the printer to bridge: "file:<path>" for a file, else a printer from List by its name,
// its port or its target.
func Choose(spec string) (Printer, error) {
	if path, ok := strings.CutPrefix(spec, "file:"); ok {
		return File{Path: path}, nil
	}
	return find(spec)
}

// File is a printer that appends what it is sent to a file, for development and tests.
type File struct{ Path string }

func (f File) Name() string  { return f.Path }
func (f File) Present() bool { return true }

func (f File) Open() (Device, error) {
	file, err := os.OpenFile(f.Path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return nil, err
	}
	return fileDevice{file}, nil
}

type fileDevice struct{ *os.File }

// State: a file never runs out of paper.
func (fileDevice) State() (paperOut, coverOpen bool) { return false, false }

// virtualPorts are the ports of Windows' own printers that print to files or apps, never to paper.
var virtualPorts = map[string]bool{"PORTPROMPT:": true, "NUL:": true, "SHRFAX:": true, "XPSPORT:": true, "FILE:": true}

// Virtual says whether a printer is one of Windows' own that never prints on paper: Microsoft Print to PDF,
// the XPS writer, OneNote and Fax.
func Virtual(f Found) bool {
	if f.Kind != KindQueue {
		return false
	}
	if virtualPorts[strings.ToUpper(f.Port)] {
		return true
	}
	for _, name := range []string{"Microsoft Print to PDF", "Microsoft XPS Document Writer", "OneNote", "Send To OneNote", "Fax"} {
		if strings.HasPrefix(f.Name, name) {
			return true
		}
	}
	return false
}
