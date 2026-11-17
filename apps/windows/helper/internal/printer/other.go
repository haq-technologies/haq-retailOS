//go:build !windows

package printer

import "errors"

// List finds no printers away from Windows; a file stands in for one with "file:<path>".
func List() ([]Found, error) {
	return nil, errors.New("printers are found on Windows only; use file:<path> to try the bridge elsewhere")
}

func find(spec string) (Printer, error) {
	return nil, ErrNotFound
}
