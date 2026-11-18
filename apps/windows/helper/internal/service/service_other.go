//go:build !windows

// Package service runs the HAQ Helper as a Windows service; away from Windows it only says so.
package service

import (
	"context"
	"errors"
)

// Name is the service's name on Windows.
const Name = "HAQHelper"

var errWindows = errors.New("the HAQ Helper service runs on Windows only")

// IsService is false away from Windows.
func IsService() bool { return false }

// Run is Windows only.
func Run(func(context.Context) error) error { return errWindows }

// Install is Windows only.
func Install(string, ...string) error { return errWindows }

// Start is Windows only.
func Start() error { return errWindows }

// Stop is Windows only.
func Stop() error { return errWindows }

// Remove is Windows only.
func Remove() error { return errWindows }

// State is Windows only.
func State() (string, error) { return "", errWindows }
