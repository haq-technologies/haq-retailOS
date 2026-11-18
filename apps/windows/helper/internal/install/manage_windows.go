//go:build windows

package install

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"unsafe"

	"golang.org/x/sys/windows"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/service"
)

// Update puts the Windows parts back after a newer setup has copied its files: the account's tasks, the
// ports, the firewall, the service and the shortcut. The shop server itself updates with `haq update`.
func Update(ctx context.Context, out io.Writer) error {
	cfg, err := config.Load(config.Path())
	if err != nil {
		return fmt.Errorf("this PC has no HAQ settings to update: %w", err)
	}
	s := steps{out: out, of: 3}
	s.next("Updating the Windows parts")
	switch cfg.Mode {
	case config.Server:
		if err := prepare(ctx, out); err != nil {
			return err
		}
		if err := startTask(ctx, serverTask); err != nil {
			return err
		}
		if err := forward(ctx, out, cfg.Address); err != nil {
			return err
		}
		bridgeFrom := ""
		if cfg.Printer != "" {
			bridgeFrom = "LocalSubnet"
		}
		if err := firewall(ctx, out, true, bridgeFrom); err != nil {
			return err
		}
	case config.Counter:
		if err := folders(ctx, out, false); err != nil {
			return err
		}
		if cfg.Printer != "" {
			if err := firewall(ctx, out, false, cfg.Server); err != nil {
				return err
			}
		}
	}
	s.next("Starting the HAQ Helper")
	if cfg.Printer != "" || cfg.Mode == config.Server {
		if err := startService(); err != nil {
			return err
		}
	}
	s.next("Opening the counter whenever Windows starts")
	if err := counterShortcut(ctx, out, cfg.Server); err != nil {
		return err
	}
	fmt.Fprintln(out, "\nUpdated.")
	return nil
}

// Stop stops the HAQ Helper and the keep-alive before a newer setup copies its files over them. On the
// server's PC the shop stops for the minute this takes.
func Stop(ctx context.Context) error {
	if err := service.Stop(); err != nil && !errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return err
	}
	_, err := psOutput(ctx, `
$task = Get-ScheduledTask -TaskPath $env:HAQ_TASK_PATH -TaskName $env:HAQ_TASK -ErrorAction SilentlyContinue
if ($task) { $task | Stop-ScheduledTask }`, map[string]string{"HAQ_TASK_PATH": taskFolder, "HAQ_TASK": serverTask})
	return err
}

// Remove takes the Windows parts away: the service, the tasks, the ports, the firewall rules, the shortcut
// and the trusted certificate. The shop itself stays, with its sales, in its distribution and in
// C:\ProgramData\HAQ, so a setup run again finds it; deleteShop removes that too, for good.
func Remove(ctx context.Context, out io.Writer, deleteShop bool) error {
	cfg, cfgErr := config.Load(config.Path())
	fmt.Fprintln(out, "Removing the HAQ Helper service, the tasks, the firewall rules and the shortcut...")
	if err := service.Remove(); err != nil {
		return err
	}
	if deleteShop && cfgErr == nil && cfg.Mode == config.Server {
		fmt.Fprintln(out, "Deleting the shop server and every sale in it...")
		_ = unregisterTask(ctx, serverTask)
		if code, err := asServerHere(ctx, out, "wsl.exe", "--unregister", cfg.Distribution); err != nil || code != 0 {
			return fmt.Errorf("could not delete the distribution (code %d): %v", code, err)
		}
	}
	for _, task := range []string{serverTask, runnerTask} {
		if err := unregisterTask(ctx, task); err != nil {
			return err
		}
	}
	if cfgErr == nil {
		removeForward(ctx, cfg.Address)
	}
	if err := removeFirewall(ctx); err != nil {
		return err
	}
	_ = os.Remove(startupShortcut())
	untrust(ctx)
	if !deleteShop {
		fmt.Fprintln(out, "Removed. The shop and its sales are kept in", config.Dir(), "and a setup run again finds them.")
		return nil
	}
	if cfgErr == nil && cfg.Mode == config.Server {
		_ = changeRight(qualified(account), batchRight, false)
		_, _ = psOutput(ctx, `
Get-CimInstance Win32_UserProfile | Where-Object { $_.LocalPath -like ('*\' + $env:HAQ_ACCOUNT) } | Remove-CimInstance -ErrorAction SilentlyContinue
Remove-LocalUser -Name $env:HAQ_ACCOUNT -ErrorAction SilentlyContinue`, map[string]string{"HAQ_ACCOUNT": account})
	}
	if err := os.RemoveAll(config.Dir()); err != nil {
		return fmt.Errorf("could not delete %s: %w", config.Dir(), err)
	}
	fmt.Fprintln(out, "Removed, with the shop's data.")
	return nil
}

// Preflight checks the PC can take the install, and says what to do about anything that stops it.
func Preflight(ctx context.Context, out io.Writer, mode config.Mode, address string) error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("run the setup as an administrator")
	}
	if v := windows.RtlGetVersion(); v.MajorVersion < 10 || v.BuildNumber < 19045 {
		return fmt.Errorf("this is Windows build %d: the setup needs Windows 10 22H2 (19045) or Windows 11", v.BuildNumber)
	}
	if address != "" {
		if err := hasAddress(address); err != nil {
			return err
		}
	}
	if mode != config.Server {
		return nil
	}
	if _, err := os.Stat(filepath.Join(os.Getenv("ProgramData"), "HAQSpike")); err == nil {
		return errors.New("the Windows spike is still installed: run its remove command first (apps/windows/spike/README.md, step 7)")
	}
	if !wslInstalled(ctx) {
		return ErrNoWSL
	}
	mem := memoryStatusEx{}
	mem.Length = uint32(unsafe.Sizeof(mem))
	if ok, _, _ := procGlobalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&mem))); ok != 0 && mem.TotalPhys < 7<<30 {
		return fmt.Errorf("this PC has %.1f GB of memory: the server needs 8 GB at the least", float64(mem.TotalPhys)/(1<<30))
	}
	var free uint64
	root, _ := windows.UTF16PtrFromString(filepath.VolumeName(config.Dir()) + `\`)
	if err := windows.GetDiskFreeSpaceEx(root, &free, nil, nil); err == nil && free < 30<<30 {
		return fmt.Errorf("drive %s has %d GB free: the server needs 30 GB", filepath.VolumeName(config.Dir()), free>>30)
	}
	fmt.Fprintln(out, "This PC can run the shop.")
	return nil
}

// memoryStatusEx is Windows' MEMORYSTATUSEX.
type memoryStatusEx struct {
	Length, MemoryLoad                                 uint32
	TotalPhys, AvailPhys, TotalPageFile, AvailPageFile uint64
	TotalVirtual, AvailVirtual, AvailExtendedVirtual   uint64
}

var procGlobalMemoryStatusEx = windows.NewLazySystemDLL("kernel32.dll").NewProc("GlobalMemoryStatusEx")

// ErrNoWSL says Windows' Linux is not turned on yet: `haq-helper setup wsl` does it, with a restart.
var ErrNoWSL = errors.New("Windows' Linux (WSL) is not turned on yet")

// InstallWSL turns on Windows' Linux, with no distribution of its own. Windows needs a restart after it.
func InstallWSL(ctx context.Context, out io.Writer) error {
	return run(ctx, out, "wsl.exe", "--install", "--no-distribution")
}

// AsServer runs argv as HAQServer through the runner, for support: the server's own commands inside it.
func AsServer(ctx context.Context, out io.Writer, argv ...string) (int, error) {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return -1, errors.New("run it in Terminal (Admin)")
	}
	return asServerHere(ctx, out, argv...)
}

// Prepare makes HAQServer, its folders and its tasks, without WSL: the first part of the server's install,
// which CI tries on a Windows runner.
func Prepare(ctx context.Context, out io.Writer) error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("run it in Terminal (Admin)")
	}
	return prepare(ctx, out)
}
