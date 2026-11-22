package main

import (
	"context"
	"net/netip"
	"sync"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/bridge"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/health"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/install"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/printer"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/wslnet"
)

// healthEvery is how often the service reads the PC's disks and clock, as host-health.sh's timer does.
const healthEvery = 15 * time.Minute

// serve is the HAQ Helper service's work, until ctx ends: the print bridge for the PC's receipt printer,
// and, on the PC that runs the server, the report of its disks and clock and the watch on its port
// forwarding.
func serve(ctx context.Context, logf func(string, ...any)) error {
	cfg, ok := waitForConfig(ctx, logf)
	if !ok {
		return nil
	}
	logf("HAQ Helper %s: this PC is a %s, at %s; the server is at %s", version, cfg.Mode, cfg.Address, cfg.Server)
	var wg sync.WaitGroup
	if cfg.Printer == "" {
		logf("no receipt printer on this PC, so no print bridge")
	} else if p, err := printer.Choose(cfg.Printer); err != nil {
		logf("the receipt printer %q: %v", cfg.Printer, err)
	} else {
		b := bridge.New(bridge.Config{Listen: cfg.Listen, Printer: p, Allow: allowFor(cfg), Logf: logf})
		wg.Go(func() {
			if err := b.Run(ctx); err != nil {
				logf("the print bridge stopped: %v", err)
			}
		})
	}
	if cfg.Mode == config.Server {
		wg.Go(func() { reportHealth(ctx, logf) })
		// After a restart, Windows' forwarding may not listen on the PC's address until it is made again (D141).
		wg.Go(func() { install.KeepForward(ctx, logf, cfg.Address) })
	}
	wg.Wait()
	return nil
}

// allowFor lets in only the shop server: on its own PC from inside WSL, elsewhere from its address.
func allowFor(cfg config.Config) bridge.Allow {
	if cfg.Mode == config.Server {
		return wslnet.Allow(wslnet.Adapters, 30*time.Second)
	}
	server := netip.MustParseAddr(cfg.Server) // Load checked it
	return bridge.Only(netip.PrefixFrom(server, server.BitLen()))
}

func reportHealth(ctx context.Context, logf func(string, ...any)) {
	for {
		r := health.Collect(ctx)
		if err := health.Write(config.StatusDir(), r); err != nil {
			logf("could not write the PC's report: %v", err)
		} else if !r.Smartctl {
			logf("Windows' disk counters could not be read this time; the health page shows the disks as not reporting")
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(healthEvery):
		}
	}
}

// waitForConfig waits for the setup's settings, so a service started before them does not fail over and
// over.
func waitForConfig(ctx context.Context, logf func(string, ...any)) (config.Config, bool) {
	for {
		cfg, err := config.Load(config.Path())
		if err == nil {
			return cfg, true
		}
		logf("waiting for the settings: %v", err)
		select {
		case <-ctx.Done():
			return config.Config{}, false
		case <-time.After(time.Minute):
		}
	}
}
