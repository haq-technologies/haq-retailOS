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
	"golang.org/x/sys/windows/registry"

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
// C:\ProgramData\HAQ, so a setup run again finds it; deleteShop removes that too, for good. It goes on past
// a part it cannot remove, so an uninstall takes away all it can, and says at the end what is left.
func Remove(ctx context.Context, out io.Writer, deleteShop bool) error {
	cfg, cfgErr := config.Load(config.Path())
	var left []error
	try := func(what string, err error) {
		if err != nil {
			left = append(left, fmt.Errorf("%s: %w", what, err))
		}
	}
	fmt.Fprintln(out, "Removing the HAQ Helper service, the tasks, the firewall rules, the shortcut and the certificate...")
	try("the HAQ Helper service", service.Remove())
	if deleteShop && cfgErr == nil && cfg.Mode == config.Server {
		fmt.Fprintln(out, "Deleting the shop server and every sale in it...")
		try("the keep-alive task", unregisterTask(ctx, serverTask))
		code, err := asServerHere(ctx, out, "wsl.exe", "--unregister", cfg.Distribution)
		if err == nil && code != 0 {
			err = fmt.Errorf("wsl --unregister stopped with code %d", code)
		}
		if err != nil {
			return fmt.Errorf("could not delete the distribution, so the shop's data is kept: %w", err)
		}
	}
	for _, task := range []string{serverTask, runnerTask} {
		try("the task "+task, unregisterTask(ctx, task))
	}
	if cfgErr == nil {
		removeForward(ctx, cfg.Address)
	}
	try("the firewall rules", removeFirewall(ctx))
	if err := os.Remove(startupShortcut()); err != nil && !errors.Is(err, os.ErrNotExist) {
		try("the start-up shortcut", err)
	}
	untrust(ctx)
	if deleteShop {
		if cfgErr == nil && cfg.Mode == config.Server {
			try("the account's right", changeRight(qualified(account), batchRight, false))
			_, err := psOutput(ctx, `
Get-CimInstance Win32_UserProfile | Where-Object { $_.LocalPath -like ('*\' + $env:HAQ_ACCOUNT) } | Remove-CimInstance -ErrorAction SilentlyContinue
Remove-LocalUser -Name $env:HAQ_ACCOUNT -ErrorAction SilentlyContinue`, map[string]string{"HAQ_ACCOUNT": account})
			try("the "+account+" account", err)
		}
		try(config.Dir(), os.RemoveAll(config.Dir()))
	}
	if len(left) > 0 {
		return fmt.Errorf("removed all but these, which are still there: %w", errors.Join(left...))
	}
	if deleteShop {
		fmt.Fprintln(out, "Removed, with the shop's data.")
	} else {
		fmt.Fprintln(out, "Removed. The shop and its sales are kept in", config.Dir(), "and a setup run again finds them.")
	}
	return nil
}

// Preflight checks the PC can take the install, and says what to do about anything that stops it (D92). A
// counter installs no Linux, so it needs only Windows 10 or 11 and a browser. The server's stops are all
// checked before Windows' Linux is turned on, so nobody restarts the PC only to be refused. Less memory than
// the server is tested with is a LowMemoryError when warn is set, for the setup's wizard to ask about;
// otherwise it is written as a warning and the install goes on.
func Preflight(ctx context.Context, out io.Writer, mode config.Mode, address string, warn bool) error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("run the setup as an administrator")
	}
	v := windows.RtlGetVersion()
	if v.MajorVersion < 10 {
		return errors.New(tooOldWindows)
	}
	if address != "" {
		if err := hasAddress(address); err != nil {
			return err
		}
	}
	if _, _, err := browser(""); err != nil {
		return err
	}
	if mode != config.Server {
		return nil
	}
	if _, err := os.Stat(filepath.Join(os.Getenv("ProgramData"), "HAQSpike")); err == nil {
		return errors.New("the Windows spike is still installed: run its remove command first (apps/windows/spike/README.md, step 7)")
	}
	revision, version := windowsRevision()
	if p := serverWindowsProblem(v.BuildNumber, revision, version); p != "" {
		return errors.New(p)
	}
	if armPC() {
		return errors.New(armProcessor)
	}
	if virtualisationTurnedOff(ctx) {
		return errors.New(virtualisationOff)
	}
	var free uint64
	root, _ := windows.UTF16PtrFromString(filepath.VolumeName(config.Dir()) + `\`)
	if err := windows.GetDiskFreeSpaceEx(root, &free, nil, nil); err == nil && free < 30<<30 {
		return fmt.Errorf("drive %s has %d GB free: the server needs 30 GB", filepath.VolumeName(config.Dir()), free>>30)
	}
	mem := memoryStatusEx{}
	mem.Length = uint32(unsafe.Sizeof(mem))
	if ok, _, _ := procGlobalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&mem))); ok != 0 && mem.TotalPhys < lowMemoryGB<<30 {
		low := LowMemoryError{GB: float64(mem.TotalPhys) / (1 << 30)}
		if warn {
			return low
		}
		fmt.Fprintln(out, "Warning:", low.Error())
	}
	if !wslInstalled(ctx) {
		return ErrNoWSL
	}
	fmt.Fprintln(out, "This PC can run the shop.")
	return nil
}

// windowsRevision is Windows' update revision (the 2311 of 19045.2311) and its version name, such as 22H2.
func windowsRevision() (uint32, string) {
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows NT\CurrentVersion`, registry.QUERY_VALUE)
	if err != nil {
		return 0, ""
	}
	defer key.Close()
	revision, _, _ := key.GetIntegerValue("UBR")
	version, _, err := key.GetStringValue("DisplayVersion")
	if err != nil {
		version, _, _ = key.GetStringValue("ReleaseId") // before 20H2
	}
	return uint32(revision), version
}

// armPC says whether the PC's own processor is ARM, under which this x64 helper runs emulated: WSL there runs
// only ARM Linux, and the server's Linux and images are x64.
func armPC() bool {
	var process, native uint16
	return windows.IsWow64Process2(windows.CurrentProcess(), &process, &native) == nil && native == imageFileMachineARM64
}

const imageFileMachineARM64 = 0xAA64

// virtualisationTurnedOff says whether the firmware keeps the processor's virtualisation from Windows, which
// WSL 2's virtual machine needs. A hypervisor already running hides the processor's own answer, and is
// proof enough. When Windows cannot say, it is not taken as off: WSL then says so itself.
func virtualisationTurnedOff(ctx context.Context) bool {
	answer, err := psOutput(ctx, `
$hypervisor = (Get-CimInstance Win32_ComputerSystem).HypervisorPresent
$firmware = (Get-CimInstance Win32_Processor | Select-Object -First 1).VirtualizationFirmwareEnabled
"$hypervisor $firmware"`, nil)
	return err == nil && answer == "False False"
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
