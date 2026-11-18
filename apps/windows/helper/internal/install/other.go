//go:build !windows

package install

import (
	"context"
	"errors"
	"io"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

var errWindows = errors.New("the setup runs on Windows only")

// ErrNoWSL says Windows' Linux is not turned on yet.
var ErrNoWSL = errors.New("Windows' Linux (WSL) is not turned on yet")

// Server is Windows only.
func Server(context.Context, Answers, string, io.Writer) (Result, error) { return Result{}, errWindows }

// Counter is Windows only.
func Counter(context.Context, Answers, io.Writer) (Result, error) { return Result{}, errWindows }

// Update is Windows only.
func Update(context.Context, io.Writer) error { return errWindows }

// Stop is Windows only.
func Stop(context.Context) error { return errWindows }

// Remove is Windows only.
func Remove(context.Context, io.Writer, bool) error { return errWindows }

// Preflight is Windows only.
func Preflight(context.Context, io.Writer, config.Mode, string) error { return errWindows }

// InstallWSL is Windows only.
func InstallWSL(context.Context, io.Writer) error { return errWindows }

// AsServer is Windows only.
func AsServer(context.Context, io.Writer, ...string) (int, error) { return -1, errWindows }

// Prepare is Windows only.
func Prepare(context.Context, io.Writer) error { return errWindows }
