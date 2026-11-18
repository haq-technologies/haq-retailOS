//go:build !windows

package drives

import "errors"

// List finds no drive letters away from Windows: the mini-PC has udev.
func List() ([]Drive, error) {
	return nil, errors.New("drive letters are found on Windows only")
}
