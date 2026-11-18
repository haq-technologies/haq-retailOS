//go:build !windows

package wslnet

import "errors"

// Adapters finds no WSL away from Windows.
func Adapters() ([]Adapter, error) {
	return nil, errors.New("WSL's network is found on Windows only")
}
