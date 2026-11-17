//go:build windows

package printer

import (
	"errors"
	"fmt"
	"runtime"
	"unsafe"

	"golang.org/x/sys/windows"
)

// The Windows print spooler, through winspool.drv: the list of print queues, their ports and states, and RAW
// jobs, which pass the bytes to the printer as they are.
var (
	winspool             = windows.NewLazySystemDLL("winspool.drv")
	procEnumPrintersW    = winspool.NewProc("EnumPrintersW")
	procOpenPrinterW     = winspool.NewProc("OpenPrinterW")
	procClosePrinter     = winspool.NewProc("ClosePrinter")
	procGetPrinterW      = winspool.NewProc("GetPrinterW")
	procStartDocPrinterW = winspool.NewProc("StartDocPrinterW")
	procStartPagePrinter = winspool.NewProc("StartPagePrinter")
	procWritePrinter     = winspool.NewProc("WritePrinter")
	procEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	procEndDocPrinter    = winspool.NewProc("EndDocPrinter")
)

const (
	printerEnumLocal       = 0x00000002
	printerEnumConnections = 0x00000004

	statusPaperOut     = 0x00000010
	statusPaperProblem = 0x00000040
	statusOffline      = 0x00000080
	statusNotAvailable = 0x00001000
	statusDoorOpen     = 0x00400000

	attributeWorkOffline = 0x00000400
)

// printerInfo2 is PRINTER_INFO_2W.
type printerInfo2 struct {
	ServerName         *uint16
	PrinterName        *uint16
	ShareName          *uint16
	PortName           *uint16
	DriverName         *uint16
	Comment            *uint16
	Location           *uint16
	DevMode            uintptr
	SepFile            *uint16
	PrintProcessor     *uint16
	Datatype           *uint16
	Parameters         *uint16
	SecurityDescriptor uintptr
	Attributes         uint32
	Priority           uint32
	DefaultPriority    uint32
	StartTime          uint32
	UntilTime          uint32
	Status             uint32
	Jobs               uint32
	AveragePPM         uint32
}

// docInfo1 is DOC_INFO_1W.
type docInfo1 struct {
	DocName    *uint16
	OutputFile *uint16
	Datatype   *uint16
}

type queue struct {
	name, port, driver string
	status, attributes uint32
}

// present: Windows marks a USB printer that is unplugged or switched off as offline.
func (q queue) present() bool {
	return q.status&(statusOffline|statusNotAvailable) == 0 && q.attributes&attributeWorkOffline == 0
}

func enumPrinters() ([]queue, error) {
	flags := uintptr(printerEnumLocal | printerEnumConnections)
	var needed, returned uint32
	r, _, err := procEnumPrintersW.Call(flags, 0, 2, 0, 0, uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if r == 0 && !errors.Is(err, windows.ERROR_INSUFFICIENT_BUFFER) {
		return nil, fmt.Errorf("listing the printers: %w", err)
	}
	if needed == 0 {
		return nil, nil
	}
	buf := make([]byte, needed)
	r, _, err = procEnumPrintersW.Call(flags, 0, 2, uintptr(unsafe.Pointer(&buf[0])), uintptr(needed),
		uintptr(unsafe.Pointer(&needed)), uintptr(unsafe.Pointer(&returned)))
	if r == 0 {
		return nil, fmt.Errorf("listing the printers: %w", err)
	}
	infos := unsafe.Slice((*printerInfo2)(unsafe.Pointer(&buf[0])), returned)
	queues := make([]queue, 0, returned)
	for _, info := range infos {
		queues = append(queues, queue{
			name:       windows.UTF16PtrToString(info.PrinterName),
			port:       windows.UTF16PtrToString(info.PortName),
			driver:     windows.UTF16PtrToString(info.DriverName),
			status:     info.Status,
			attributes: info.Attributes,
		})
	}
	runtime.KeepAlive(buf)
	return queues, nil
}

// queueDevice is a print queue open for one RAW job, or for its state.
type queueDevice struct {
	h       windows.Handle
	started bool
}

func openQueue(name string) (Device, error) {
	n, err := windows.UTF16PtrFromString(name)
	if err != nil {
		return nil, err
	}
	var h windows.Handle
	r, _, err := procOpenPrinterW.Call(uintptr(unsafe.Pointer(n)), uintptr(unsafe.Pointer(&h)), 0)
	if r == 0 {
		return nil, fmt.Errorf("opening the print queue %s: %w", name, err)
	}
	return &queueDevice{h: h}, nil
}

func (d *queueDevice) Write(p []byte) (int, error) {
	if len(p) == 0 {
		return 0, nil
	}
	if !d.started {
		docName, _ := windows.UTF16PtrFromString("HAQ Retail OS receipt")
		raw, _ := windows.UTF16PtrFromString("RAW")
		doc := docInfo1{DocName: docName, Datatype: raw}
		if r, _, err := procStartDocPrinterW.Call(uintptr(d.h), 1, uintptr(unsafe.Pointer(&doc))); r == 0 {
			return 0, fmt.Errorf("starting the job: %w", err)
		}
		if r, _, err := procStartPagePrinter.Call(uintptr(d.h)); r == 0 {
			procEndDocPrinter.Call(uintptr(d.h))
			return 0, fmt.Errorf("starting the job: %w", err)
		}
		d.started = true
	}
	var written uint32
	r, _, err := procWritePrinter.Call(uintptr(d.h), uintptr(unsafe.Pointer(&p[0])), uintptr(len(p)), uintptr(unsafe.Pointer(&written)))
	if r == 0 {
		return int(written), fmt.Errorf("writing the job: %w", err)
	}
	return int(written), nil
}

// Close ends the job, which the spooler then prints, and closes the queue.
func (d *queueDevice) Close() error {
	var err error
	if d.started {
		procEndPagePrinter.Call(uintptr(d.h))
		if r, _, e := procEndDocPrinter.Call(uintptr(d.h)); r == 0 {
			err = fmt.Errorf("ending the job: %w", e)
		}
	}
	procClosePrinter.Call(uintptr(d.h))
	return err
}

// State reads the queue's state, which is as much as Windows knows of the printer's paper and cover.
func (d *queueDevice) State() (paperOut, coverOpen bool) {
	var needed uint32
	procGetPrinterW.Call(uintptr(d.h), 2, 0, 0, uintptr(unsafe.Pointer(&needed)))
	if needed == 0 {
		return false, false
	}
	buf := make([]byte, needed)
	if r, _, _ := procGetPrinterW.Call(uintptr(d.h), 2, uintptr(unsafe.Pointer(&buf[0])), uintptr(needed), uintptr(unsafe.Pointer(&needed))); r == 0 {
		return false, false
	}
	info := (*printerInfo2)(unsafe.Pointer(&buf[0]))
	status := info.Status
	runtime.KeepAlive(buf)
	return status&(statusPaperOut|statusPaperProblem) != 0, status&statusDoorOpen != 0
}
