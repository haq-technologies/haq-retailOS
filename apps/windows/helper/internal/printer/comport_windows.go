//go:build windows

package printer

import (
	"strings"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

// A printer on a USB virtual COM port, as some thermal printers' drivers make. It reads as well as writes.
type comDev struct{ h windows.Handle }

func openCOM(port string) (Device, error) {
	p, err := windows.UTF16PtrFromString(`\\.\` + port)
	if err != nil {
		return nil, err
	}
	h, err := windows.CreateFile(p, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, nil, windows.OPEN_EXISTING, 0, 0)
	if err != nil {
		return nil, err
	}
	d := &comDev{h: h}
	if err := d.timeouts(time.Second); err != nil {
		windows.CloseHandle(h)
		return nil, err
	}
	return d, nil
}

// timeouts: a read returns what came within read, and a write may take 10 seconds.
func (d *comDev) timeouts(read time.Duration) error {
	return windows.SetCommTimeouts(d.h, &windows.CommTimeouts{
		ReadIntervalTimeout:       50,
		ReadTotalTimeoutConstant:  uint32(max(read, time.Millisecond) / time.Millisecond),
		WriteTotalTimeoutConstant: 10000,
	})
}

func (d *comDev) Write(p []byte) (int, error) {
	var done uint32
	err := windows.WriteFile(d.h, p, &done, nil)
	return int(done), err
}

func (d *comDev) ReadTimeout(p []byte, timeout time.Duration) (int, error) {
	if err := d.timeouts(timeout); err != nil {
		return 0, err
	}
	var done uint32
	err := windows.ReadFile(d.h, p, &done, nil)
	return int(done), err
}

func (d *comDev) Close() error { return windows.CloseHandle(d.h) }

// comPresent says whether Windows has the COM port now, as a USB one has only while it is plugged in.
func comPresent(port string) bool {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `HARDWARE\DEVICEMAP\SERIALCOMM`, registry.QUERY_VALUE)
	if err != nil {
		return false
	}
	defer k.Close()
	names, err := k.ReadValueNames(0)
	if err != nil {
		return false
	}
	for _, name := range names {
		if v, _, err := k.GetStringValue(name); err == nil && strings.EqualFold(v, port) {
			return true
		}
	}
	return false
}
