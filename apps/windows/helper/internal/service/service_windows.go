//go:build windows

// Package service runs the HAQ Helper as a Windows service (D86, D87): the print bridge for the PC's receipt
// printer and, on the PC that runs the shop server, the report of its disks and clock. It runs as the system
// account, which reaches USB printers and Windows' disk counters, from Windows' start; Windows starts it
// again if it fails.
package service

import (
	"context"
	"errors"
	"fmt"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const (
	// Name is the service's name, as `sc query HAQHelper` takes it.
	Name        = "HAQHelper"
	displayName = "HAQ Helper"
	description = "HAQ Retail OS: the print bridge for this PC's receipt printer, and the PC's disk and clock report for the shop server."
	waitFor     = 30 * time.Second
)

// IsService says whether Windows started this program as a service.
func IsService() bool {
	ok, err := svc.IsWindowsService()
	return err == nil && ok
}

type handler struct{ work func(context.Context) error }

func (h handler) Execute(_ []string, requests <-chan svc.ChangeRequest, status chan<- svc.Status) (bool, uint32) {
	status <- svc.Status{State: svc.StartPending}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- h.work(ctx) }()
	status <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	for {
		select {
		case err := <-done:
			if err != nil {
				return true, 1 // a failure, which Windows' recovery answers by starting it again
			}
			return false, 0
		case r := <-requests:
			switch r.Cmd {
			case svc.Interrogate:
				status <- r.CurrentStatus
			case svc.Stop, svc.Shutdown:
				status <- svc.Status{State: svc.StopPending, WaitHint: 10_000}
				cancel()
				select {
				case <-done:
				case <-time.After(10 * time.Second):
				}
				return false, 0
			}
		}
	}
}

// Run runs work as the service until Windows stops it.
func Run(work func(context.Context) error) error { return svc.Run(Name, handler{work}) }

// Install makes the service, or points the one there at this program: it starts with Windows, as the
// system account, and Windows starts it again 5, 10 and 30 seconds after a failure.
func Install(exe string, args ...string) error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(Name)
	if err == nil {
		cfg, err := s.Config()
		if err != nil {
			s.Close()
			return err
		}
		cfg.BinaryPathName = windows.EscapeArg(exe)
		for _, a := range args {
			cfg.BinaryPathName += " " + windows.EscapeArg(a)
		}
		cfg.DisplayName, cfg.Description, cfg.StartType = displayName, description, mgr.StartAutomatic
		if err := s.UpdateConfig(cfg); err != nil {
			s.Close()
			return err
		}
	} else {
		s, err = m.CreateService(Name, exe, mgr.Config{
			DisplayName: displayName, Description: description, StartType: mgr.StartAutomatic, ErrorControl: mgr.ErrorNormal,
		}, args...)
		if err != nil {
			return err
		}
	}
	defer s.Close()
	actions := []mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 10 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}
	if err := s.SetRecoveryActions(actions, uint32((24 * time.Hour).Seconds())); err != nil {
		return err
	}
	return s.SetRecoveryActionsOnNonCrashFailures(true)
}

// Start starts the service and waits until it runs.
func Start() error {
	return withService(func(s *mgr.Service) error {
		if err := s.Start(); err != nil && !errors.Is(err, windows.ERROR_SERVICE_ALREADY_RUNNING) {
			return err
		}
		return waitState(s, svc.Running)
	})
}

// Stop stops the service and waits until it has.
func Stop() error {
	return withService(func(s *mgr.Service) error {
		st, err := s.Query()
		if err != nil {
			return err
		}
		if st.State == svc.Stopped {
			return nil
		}
		if _, err := s.Control(svc.Stop); err != nil && !errors.Is(err, windows.ERROR_SERVICE_NOT_ACTIVE) {
			return err
		}
		return waitState(s, svc.Stopped)
	})
}

// Remove stops the service and removes it. A service that is not there is not a fault.
func Remove() error {
	if err := Stop(); err != nil {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return nil
		}
		return err
	}
	return withService(func(s *mgr.Service) error { return s.Delete() })
}

// State says how the service is: "running", "stopped", or "not installed".
func State() (string, error) {
	var state string
	err := withService(func(s *mgr.Service) error {
		st, err := s.Query()
		if err != nil {
			return err
		}
		switch st.State {
		case svc.Running:
			state = "running"
		case svc.Stopped:
			state = "stopped"
		default:
			state = fmt.Sprintf("changing (%d)", st.State)
		}
		return nil
	})
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return "not installed", nil
	}
	return state, err
}

func withService(do func(*mgr.Service) error) error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	s, err := m.OpenService(Name)
	if err != nil {
		return err
	}
	defer s.Close()
	return do(s)
}

func waitState(s *mgr.Service, want svc.State) error {
	deadline := time.Now().Add(waitFor)
	for {
		st, err := s.Query()
		if err != nil {
			return err
		}
		if st.State == want {
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("the service did not reach state %d within %s (it is %d)", want, waitFor, st.State)
		}
		time.Sleep(300 * time.Millisecond)
	}
}
