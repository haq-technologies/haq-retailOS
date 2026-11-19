//go:build windows

package install

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// The WSL the server's Linux runs on: Microsoft's own installer, from its release on GitHub, checked against
// its SHA-256 before it runs. The same steps work on every Windows the server takes (D92), with no need for
// the `wsl --install` that Windows 10 has only in some versions. A release moves it on.
const (
	wslVersion = "3.0.5"
	wslURL     = "https://github.com/microsoft/WSL/releases/download/3.0.5/wsl.3.0.5.0.x64.msi"
	wslSHA256  = "2313efc5873e3f8552f42e77f950fabd913c0289078fa89d2100f3421a525eaa"
	wslSizeMB  = 370
)

// doneRestartCode is DISM's and Windows Installer's code for done once Windows restarts.
const doneRestartCode = 3010

// InstallWSL turns on Windows' Linux, with no distribution of its own: Windows' Virtual Machine Platform and
// Windows Subsystem for Linux features, then Microsoft's WSL. Windows needs a restart after it.
func InstallWSL(ctx context.Context, out io.Writer) error {
	fmt.Fprintln(out, "1 of 3. Turning on Windows' Virtual Machine Platform and Windows Subsystem for Linux")
	for _, feature := range []string{"VirtualMachinePlatform", "Microsoft-Windows-Subsystem-Linux"} {
		if err := installer(ctx, out, "dism.exe", "/online", "/enable-feature", "/featurename:"+feature, "/all", "/norestart"); err != nil {
			return fmt.Errorf("turning on %s: %w", feature, err)
		}
	}

	fmt.Fprintf(out, "2 of 3. Downloading WSL %s from Microsoft, about %d MB\n", wslVersion, wslSizeMB)
	msi := filepath.Join(config.Dir(), "downloads", "wsl.msi")
	if err := download(ctx, out, wslURL, wslSHA256, msi); err != nil {
		return fmt.Errorf("downloading WSL: %w. Check this PC is on the internet, then try again", err)
	}
	defer os.Remove(msi)

	fmt.Fprintln(out, "3 of 3. Installing it")
	if err := installer(ctx, out, "msiexec.exe", "/i", msi, "/quiet", "/norestart", "/l*v", filepath.Join(config.LogDir(), "wsl-install.log")); err != nil {
		return fmt.Errorf("installing WSL: %w; logs\\wsl-install.log says why", err)
	}
	fmt.Fprintln(out, "Windows' Linux is turned on. Windows needs a restart to finish it.")
	return nil
}

// installer runs DISM or Windows Installer, for which a restart still to come is success.
func installer(ctx context.Context, out io.Writer, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Stdout, cmd.Stderr = out, out
	err := cmd.Run()
	var exit *exec.ExitError
	if errors.As(err, &exit) && exit.ExitCode() == doneRestartCode {
		return nil
	}
	if err != nil {
		return fmt.Errorf("%s: %w", name, err)
	}
	return nil
}
