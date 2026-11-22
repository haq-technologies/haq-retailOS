package install

import (
	"context"
	"io"
	"net"
	"time"
)

// KeepForward keeps the shop reachable at the PC's address for as long as ctx runs (D141): the HAQ Helper
// service runs it on the PC that runs the shop, from Windows' start. A connection the port forwarding
// accepts counts as an answer, even while the server inside is still starting: that is the starting page's
// work, not the forwarding's.
func KeepForward(ctx context.Context, logf func(string, ...any), address string) {
	watchForward(ctx, logf, forwardEvery,
		func() bool { return hasAddress(address) == nil },
		func() bool {
			c, err := net.DialTimeout("tcp", net.JoinHostPort(address, "443"), 3*time.Second)
			if err != nil {
				return false
			}
			_ = c.Close()
			return true
		},
		func() error { return forward(ctx, io.Discard, address) })
}
