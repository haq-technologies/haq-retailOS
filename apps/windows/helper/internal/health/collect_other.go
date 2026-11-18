//go:build !windows

package health

import (
	"context"
	"errors"
	"time"
)

// Collect reads nothing away from Windows: the mini-PC has deploy/host-health.sh.
func Collect(context.Context) Report {
	return Build(time.Now(), nil, errors.New("the disks are read on Windows only"), nil)
}
