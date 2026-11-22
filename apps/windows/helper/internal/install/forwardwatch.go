package install

import (
	"context"
	"time"
)

// forwardEvery is how often the HAQ Helper service checks that the PC's address answers for the shop (D141).
const forwardEvery = 30 * time.Second

// watchForward keeps the shop reachable at the PC's address (D141). Windows keeps the port forwarding's
// rules across a restart, but its IP Helper service listens on the address only if the PC has it when the
// service starts, and never tries again: at start-up the network often comes a little later, so after a
// restart nothing answered until the forwarding was made again by hand. So, once the PC has its address,
// whenever nothing answers there, the forwarding is made again. It says so in the log when it repairs it
// and when the address answers again, not at every check.
func watchForward(ctx context.Context, logf func(string, ...any), every time.Duration,
	hasAddress, answers func() bool, repair func() error) {
	broken := false
	for {
		if hasAddress() {
			switch {
			case answers():
				if broken {
					logf("the shop answers at this PC's address again")
					broken = false
				}
			default:
				if !broken {
					logf("nothing answered at this PC's address for the shop, so the port forwarding is made again")
				}
				broken = true
				if err := repair(); err != nil {
					logf("the port forwarding could not be made again: %v", err)
				}
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(every):
		}
	}
}
