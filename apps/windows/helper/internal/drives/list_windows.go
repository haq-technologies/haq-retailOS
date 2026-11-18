//go:build windows

package drives

import (
	"golang.org/x/sys/windows"
)

func init() {
	// A card reader with no card asks "insert a disk" in a dialog box, which a service must never show.
	windows.SetErrorMode(windows.SEM_FAILCRITICALERRORS | windows.SEM_NOOPENFILEERRORBOX)
}

// List finds the drives with letters whose volumes can be read now: flash drives and other disks, not CD
// drives or network shares.
func List() ([]Drive, error) {
	mask, err := windows.GetLogicalDrives()
	if err != nil {
		return nil, err
	}
	var list []Drive
	for i := range 26 {
		if mask&(1<<i) == 0 {
			continue
		}
		letter := string(rune('A'+i)) + ":"
		root, _ := windows.UTF16PtrFromString(letter + `\`)
		switch windows.GetDriveType(root) {
		case windows.DRIVE_REMOVABLE, windows.DRIVE_FIXED:
		default:
			continue
		}
		label := make([]uint16, windows.MAX_PATH+1)
		var serial uint32
		if err := windows.GetVolumeInformation(root, &label[0], uint32(len(label)), &serial, nil, nil, nil, 0); err != nil {
			continue // a reader with no card in it
		}
		list = append(list, Drive{Letter: letter, Label: windows.UTF16ToString(label), Serial: serial})
	}
	return list, nil
}
