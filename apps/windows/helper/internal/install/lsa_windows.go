//go:build windows

package install

import (
	"fmt"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

// An account's own rights, through Windows' LSA policy calls, as the spike's account-rights.ps1 does (and
// CI tests): HAQServer gets "Log on as a batch job" and nothing else.

var (
	advapi                     = windows.NewLazySystemDLL("advapi32.dll")
	procLsaOpenPolicy          = advapi.NewProc("LsaOpenPolicy")
	procLsaAddAccountRights    = advapi.NewProc("LsaAddAccountRights")
	procLsaRemoveAccountRights = advapi.NewProc("LsaRemoveAccountRights")
	procLsaClose               = advapi.NewProc("LsaClose")
	procLsaNtStatusToWinError  = advapi.NewProc("LsaNtStatusToWinError")
)

const (
	policyCreateAccount      = 0x00000010
	policyLookupNames        = 0x00000800
	statusObjectNameNotFound = 0xC0000034
)

type lsaString struct {
	Length        uint16
	MaximumLength uint16
	Buffer        *uint16
}

type lsaObjectAttributes struct {
	Length                   uint32
	RootDirectory            windows.Handle
	ObjectName               *lsaString
	Attributes               uint32
	SecurityDescriptor       uintptr
	SecurityQualityOfService uintptr
}

func lsaError(status uintptr) error {
	code, _, _ := procLsaNtStatusToWinError.Call(status)
	return syscall.Errno(code)
}

func lsaOpen() (windows.Handle, error) {
	attrs := lsaObjectAttributes{}
	attrs.Length = uint32(unsafe.Sizeof(attrs))
	var policy windows.Handle
	status, _, _ := procLsaOpenPolicy.Call(0, uintptr(unsafe.Pointer(&attrs)), policyCreateAccount|policyLookupNames,
		uintptr(unsafe.Pointer(&policy)))
	if status != 0 {
		return 0, fmt.Errorf("opening Windows' security policy: %w", lsaError(status))
	}
	return policy, nil
}

// changeRight grants or revokes one of an account's own rights. Revoking a right it does not hold is not a
// fault.
func changeRight(name, right string, grant bool) error {
	sid, _, _, err := windows.LookupSID("", name)
	if err != nil {
		return fmt.Errorf("finding %s: %w", name, err)
	}
	policy, err := lsaOpen()
	if err != nil {
		return err
	}
	defer procLsaClose.Call(uintptr(policy))
	text, err := windows.UTF16FromString(right)
	if err != nil {
		return err
	}
	s := lsaString{Length: uint16((len(text) - 1) * 2), MaximumLength: uint16(len(text) * 2), Buffer: &text[0]}
	var status uintptr
	if grant {
		status, _, _ = procLsaAddAccountRights.Call(uintptr(policy), uintptr(unsafe.Pointer(sid)), uintptr(unsafe.Pointer(&s)), 1)
	} else {
		status, _, _ = procLsaRemoveAccountRights.Call(uintptr(policy), uintptr(unsafe.Pointer(sid)), 0, uintptr(unsafe.Pointer(&s)), 1)
		if status == statusObjectNameNotFound {
			return nil
		}
	}
	if status != 0 {
		return fmt.Errorf("changing %s's right %s: %w", name, right, lsaError(status))
	}
	return nil
}
