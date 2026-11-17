//go:build windows

package printer

import (
	"errors"
	"strings"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

// guidUSBPrint is GUID_DEVINTERFACE_USBPRINT: Windows' USB printing device, which reads as well as writes,
// so a printer on it answers the status query.
var guidUSBPrint = windows.GUID{Data1: 0x28d78fad, Data2: 0x5a12, Data3: 0x11d1, Data4: [8]byte{0xae, 0x5b, 0x00, 0x00, 0xf8, 0x03, 0xa8, 0xc2}}

type usbDevice struct {
	path        string
	port        int // the n of USBnnn, the port a print queue names; 0 when Windows gave it none
	description string
}

// usbDevices lists the USB printing devices plugged in now. Windows keeps each one's port number (the n of
// USB001) under its device interface's Device Parameters, which ties a print queue on that port to it.
func usbDevices() []usbDevice {
	paths, err := windows.CM_Get_Device_Interface_List("", &guidUSBPrint, windows.CM_GET_DEVICE_INTERFACE_LIST_PRESENT)
	if err != nil {
		return nil
	}
	devices := make([]usbDevice, 0, len(paths))
	for _, path := range paths {
		if path == "" {
			continue
		}
		d := usbDevice{path: path}
		key := `SYSTEM\CurrentControlSet\Control\DeviceClasses\{28d78fad-5a12-11d1-ae5b-0000f803a8c2}\##?#` +
			strings.TrimPrefix(path, `\\?\`) + `\#\Device Parameters`
		if k, err := registry.OpenKey(registry.LOCAL_MACHINE, key, registry.QUERY_VALUE); err == nil {
			base, _, _ := k.GetStringValue("Base Name")
			if n, _, err := k.GetIntegerValue("Port Number"); err == nil && strings.EqualFold(base, "USB") {
				d.port = int(n)
			}
			d.description, _, _ = k.GetStringValue("Port Description")
			k.Close()
		}
		devices = append(devices, d)
	}
	return devices
}

// usbDev is a USB printing device open for one job or one status query, with overlapped I/O so a read
// for the printer's answer can stop waiting.
type usbDev struct{ h windows.Handle }

func openUSB(path string) (Device, error) {
	p, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, err
	}
	h, err := windows.CreateFile(p, windows.GENERIC_READ|windows.GENERIC_WRITE, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE,
		nil, windows.OPEN_EXISTING, windows.FILE_FLAG_OVERLAPPED, 0)
	if err != nil {
		return nil, err
	}
	return &usbDev{h: h}, nil
}

const writeWait = 10 * time.Second

func (d *usbDev) Write(p []byte) (int, error) {
	written := 0
	for written < len(p) {
		end := min(len(p), written+64<<10)
		n, err := d.io(false, p[written:end], writeWait)
		written += n
		if err != nil {
			return written, err
		}
		if n == 0 {
			return written, errors.New("the printer took nothing")
		}
	}
	return written, nil
}

func (d *usbDev) ReadTimeout(p []byte, timeout time.Duration) (int, error) {
	return d.io(true, p, timeout)
}

// io runs one overlapped read or write and waits for it at most timeout; a read that times out gives 0
// bytes and no error, since a printer that does not answer is not a fault.
func (d *usbDev) io(read bool, p []byte, timeout time.Duration) (int, error) {
	ev, err := windows.CreateEvent(nil, 1, 0, nil)
	if err != nil {
		return 0, err
	}
	defer windows.CloseHandle(ev)
	ov := windows.Overlapped{HEvent: ev}
	var done uint32
	if read {
		err = windows.ReadFile(d.h, p, &done, &ov)
	} else {
		err = windows.WriteFile(d.h, p, &done, &ov)
	}
	if err != nil && !errors.Is(err, windows.ERROR_IO_PENDING) {
		return 0, err
	}
	ms := uint32(max(timeout, time.Millisecond) / time.Millisecond)
	if event, _ := windows.WaitForSingleObject(ev, ms); event == uint32(windows.WAIT_TIMEOUT) {
		_ = windows.CancelIoEx(d.h, &ov)
	}
	// Waits for the I/O to end, cancelled or not, so the buffer is never written after this returns.
	err = windows.GetOverlappedResult(d.h, &ov, &done, true)
	if errors.Is(err, windows.ERROR_OPERATION_ABORTED) {
		if read {
			return int(done), nil
		}
		return int(done), errors.New("the printer did not take the receipt in time")
	}
	return int(done), err
}

func (d *usbDev) Close() error { return windows.CloseHandle(d.h) }
