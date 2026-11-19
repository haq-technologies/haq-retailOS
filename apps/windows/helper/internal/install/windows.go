//go:build windows

package install

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf16"

	"golang.org/x/sys/windows/registry"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// run runs a Windows program, its output to out.
func run(ctx context.Context, out io.Writer, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Stdout, cmd.Stderr = out, out
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s %s: %w", name, strings.Join(args, " "), err)
	}
	return nil
}

// ps runs a PowerShell script. Values go in through the environment, never on the command line, which
// other programs can read; the script itself is encoded, so no quoting can break it.
func ps(ctx context.Context, out io.Writer, script string, env map[string]string) error {
	// Errors as plain text: PowerShell writes them in its own XML when its error output is not a console. And
	// success said outright: a script whose last command looked for something with -ErrorAction
	// SilentlyContinue and found nothing would otherwise end with code 1 and no message.
	wrapped := "$ErrorActionPreference = 'Stop'\n$ProgressPreference = 'SilentlyContinue'\ntry {\n" + script +
		"\nexit 0\n} catch { [Console]::Error.WriteLine(($_ | Out-String).Trim()); exit 1 }"
	units := utf16.Encode([]rune(wrapped))
	raw := make([]byte, len(units)*2)
	for i, u := range units {
		binary.LittleEndian.PutUint16(raw[i*2:], u)
	}
	cmd := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
		"-EncodedCommand", base64.StdEncoding.EncodeToString(raw))
	cmd.Env = windowsPowerShellEnv()
	for k, v := range env {
		cmd.Env = append(cmd.Env, k+"="+v)
	}
	var errOut bytes.Buffer
	cmd.Stdout = out
	cmd.Stderr = &errOut
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%w: %s", err, strings.TrimSpace(errOut.String()))
	}
	return nil
}

// windowsPowerShellEnv is this program's environment for Windows PowerShell, without PowerShell 7's module
// path: started from PowerShell 7, Windows PowerShell would look for its own modules there and fail.
func windowsPowerShellEnv() []string {
	var env []string
	for _, kv := range os.Environ() {
		if !strings.HasPrefix(strings.ToUpper(kv), "PSMODULEPATH=") {
			env = append(env, kv)
		}
	}
	return env
}

func psOutput(ctx context.Context, script string, env map[string]string) (string, error) {
	var out bytes.Buffer
	err := ps(ctx, &out, script, env)
	return strings.TrimSpace(out.String()), err
}

func qualified(name string) string { return os.Getenv("COMPUTERNAME") + `\` + name }

// ensureAccount makes HAQServer, or sets a new password on it: a local account, hidden from the sign-in
// screen, whose only right is to be signed in by its scheduled tasks.
func ensureAccount(ctx context.Context, out io.Writer, password string) error {
	err := ps(ctx, out, `
$secure = ConvertTo-SecureString $env:HAQ_PASSWORD -AsPlainText -Force
if (Get-LocalUser -Name $env:HAQ_ACCOUNT -ErrorAction SilentlyContinue) {
    Set-LocalUser -Name $env:HAQ_ACCOUNT -Password $secure -PasswordNeverExpires $true
    Enable-LocalUser -Name $env:HAQ_ACCOUNT
} else {
    New-LocalUser -Name $env:HAQ_ACCOUNT -Password $secure -PasswordNeverExpires -UserMayNotChangePassword -AccountNeverExpires `+"`"+`
        -Description 'HAQ Retail OS: runs the hidden shop server' | Out-Null
}`, map[string]string{"HAQ_ACCOUNT": account, "HAQ_PASSWORD": password})
	if err != nil {
		return fmt.Errorf("making the %s account: %w", account, err)
	}
	key, _, err := registry.CreateKey(registry.LOCAL_MACHINE,
		`SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList`, registry.SET_VALUE)
	if err != nil {
		return err
	}
	defer key.Close()
	if err := key.SetDWordValue(account, 0); err != nil {
		return err
	}
	return changeRight(qualified(account), batchRight, true)
}

// folders makes C:\ProgramData\HAQ: administrators and Windows own it; HAQServer, on the server's PC,
// reads it and writes only its logs, the distribution, the install's files and the runner's output.
func folders(ctx context.Context, out io.Writer, server bool) error {
	root := config.Dir()
	subs := []string{"logs", "status", "install", filepath.Join("runner", "out")}
	if server {
		subs = append(subs, "wsl")
	}
	for _, sub := range append([]string{""}, subs...) {
		if err := os.MkdirAll(filepath.Join(root, sub), 0o755); err != nil {
			return err
		}
	}
	grants := []string{root, "/inheritance:r", "/grant:r", "*S-1-5-32-544:(OI)(CI)F", "*S-1-5-18:(OI)(CI)F"}
	if server {
		grants = append(grants, qualified(account)+":(OI)(CI)RX")
	}
	if err := run(ctx, io.Discard, "icacls.exe", grants...); err != nil {
		return err
	}
	if server {
		for _, sub := range []string{"logs", "install", "wsl", filepath.Join("runner", "out")} {
			if err := run(ctx, io.Discard, "icacls.exe", filepath.Join(root, sub), "/grant", qualified(account)+":(OI)(CI)M"); err != nil {
				return err
			}
		}
	}
	return nil
}

// registerTask registers a task that runs this program as HAQServer: the runner on demand, or the
// keep-alive from Windows' start, before anyone signs in, started again a minute after it stops.
func registerTask(ctx context.Context, out io.Writer, name, args string, atStartup bool, password string) error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	startup := "0"
	if atStartup {
		startup = "1"
	}
	err = ps(ctx, out, `
$action = New-ScheduledTaskAction -Execute $env:HAQ_EXE -Argument $env:HAQ_ARGS -WorkingDirectory $env:HAQ_DIR
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `+"`"+`
    -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
$task = @{ TaskPath = $env:HAQ_TASK_PATH; TaskName = $env:HAQ_TASK; Action = $action; Settings = $settings
    User = $env:HAQ_USER; Password = $env:HAQ_PASSWORD; Force = $true }
if ($env:HAQ_AT_STARTUP -eq '1') { $task.Trigger = New-ScheduledTaskTrigger -AtStartup }
Register-ScheduledTask @task | Out-Null`, map[string]string{
		"HAQ_EXE": exe, "HAQ_ARGS": args, "HAQ_DIR": filepath.Dir(exe), "HAQ_TASK_PATH": taskFolder, "HAQ_TASK": name,
		"HAQ_USER": qualified(account), "HAQ_PASSWORD": password, "HAQ_AT_STARTUP": startup,
	})
	if err != nil {
		return fmt.Errorf("registering the task %s: %w", name, err)
	}
	return nil
}

func startTask(ctx context.Context, name string) error {
	_, err := psOutput(ctx, `Start-ScheduledTask -TaskPath $env:HAQ_TASK_PATH -TaskName $env:HAQ_TASK`,
		map[string]string{"HAQ_TASK_PATH": taskFolder, "HAQ_TASK": name})
	return err
}

func unregisterTask(ctx context.Context, name string) error {
	_, err := psOutput(ctx, `
$task = Get-ScheduledTask -TaskPath $env:HAQ_TASK_PATH -TaskName $env:HAQ_TASK -ErrorAction SilentlyContinue
if ($task) { $task | Stop-ScheduledTask -ErrorAction SilentlyContinue; $task | Unregister-ScheduledTask -Confirm:$false }`,
		map[string]string{"HAQ_TASK_PATH": taskFolder, "HAQ_TASK": name})
	return err
}

// taskRunner is the runner task, as asServer starts it.
type taskRunner struct{}

func (taskRunner) Start(ctx context.Context) error { return startTask(ctx, runnerTask) }

// State says whether the runner runs and, if not, why: its last result, and the refused sign-in Windows
// logged for HAQServer, as the spike's first run needed.
func (taskRunner) State(ctx context.Context, since time.Time) (bool, string, error) {
	state, err := psOutput(ctx, `
$task = Get-ScheduledTask -TaskPath $env:HAQ_TASK_PATH -TaskName $env:HAQ_TASK
$info = $task | Get-ScheduledTaskInfo
$why = ''
$since = [DateTime]::Parse($env:HAQ_SINCE).ToLocalTime()
foreach ($e in @(Get-WinEvent -FilterHashtable @{ LogName = 'Security'; Id = 4625; StartTime = $since } -MaxEvents 50 -ErrorAction SilentlyContinue)) {
    $data = @{}
    foreach ($d in ([xml]$e.ToXml()).Event.EventData.Data) { $data[$d.GetAttribute('Name')] = $d.InnerText }
    if ($data['TargetUserName'] -eq $env:HAQ_ACCOUNT) { $why = "; Windows refused its sign-in with status $($data['Status'])"; break }
}
'{0}|Windows'' result 0x{1:X8}{2}' -f $task.State, $info.LastTaskResult, $why`, map[string]string{
		"HAQ_TASK_PATH": taskFolder, "HAQ_TASK": runnerTask, "HAQ_ACCOUNT": account,
		"HAQ_SINCE": since.Add(-5 * time.Second).UTC().Format(time.RFC3339),
	})
	if err != nil {
		return false, "", err
	}
	running, why, _ := strings.Cut(state, "|")
	return running == "Running", why, nil
}

// asServerHere runs argv as HAQServer through the runner task, with its output to out.
func asServerHere(ctx context.Context, out io.Writer, argv ...string) (int, error) {
	return asServer(ctx, RunnerDir(), taskRunner{}, out, argv...)
}

// inside runs argv as root inside the distribution, with no shell between, and fails unless it succeeds.
func inside(ctx context.Context, out io.Writer, distribution string, argv ...string) error {
	code, err := asServerHere(ctx, out, append([]string{"wsl.exe", "-d", distribution, "-u", "root", "-e"}, argv...)...)
	if err != nil {
		return err
	}
	if code != 0 {
		return fmt.Errorf("%s stopped with code %d; the output above says why", strings.Join(argv, " "), code)
	}
	return nil
}

// firewall lets the shop's network reach the server, and only the server reach the print bridge.
func firewall(ctx context.Context, out io.Writer, server bool, bridgeFrom string) error {
	return ps(ctx, out, `
Get-NetFirewallRule -Group $env:HAQ_GROUP -ErrorAction SilentlyContinue | Remove-NetFirewallRule
if ($env:HAQ_SERVER -eq '1') {
    New-NetFirewallRule -DisplayName 'HAQ Retail OS: the shop server' -Group $env:HAQ_GROUP -Direction Inbound -Protocol TCP `+"`"+`
        -LocalPort 80, 443 -RemoteAddress LocalSubnet -Action Allow -Profile Any | Out-Null
}
if ($env:HAQ_BRIDGE_FROM -ne '') {
    New-NetFirewallRule -DisplayName 'HAQ Retail OS: the print bridge' -Group $env:HAQ_GROUP -Direction Inbound -Protocol TCP `+"`"+`
        -LocalPort 9100 -RemoteAddress $env:HAQ_BRIDGE_FROM -Action Allow -Profile Any | Out-Null
}`, map[string]string{"HAQ_GROUP": ruleGroup, "HAQ_SERVER": map[bool]string{true: "1", false: "0"}[server], "HAQ_BRIDGE_FROM": bridgeFrom})
}

func removeFirewall(ctx context.Context) error {
	_, err := psOutput(ctx, `Get-NetFirewallRule -Group $env:HAQ_GROUP -ErrorAction SilentlyContinue | Remove-NetFirewallRule`,
		map[string]string{"HAQ_GROUP": ruleGroup})
	return err
}

// forward passes the PC's address, ports 80 and 443, to the server inside WSL, which WSL itself makes
// reachable on 127.0.0.1.
func forward(ctx context.Context, out io.Writer, address string) error {
	for _, port := range []string{"80", "443"} {
		_ = run(ctx, io.Discard, "netsh.exe", "interface", "portproxy", "delete", "v4tov4", "listenaddress="+address, "listenport="+port)
		if err := run(ctx, out, "netsh.exe", "interface", "portproxy", "add", "v4tov4", "listenaddress="+address,
			"listenport="+port, "connectaddress=127.0.0.1", "connectport="+port); err != nil {
			return err
		}
	}
	return nil
}

func removeForward(ctx context.Context, address string) {
	for _, port := range []string{"80", "443"} {
		_ = run(ctx, io.Discard, "netsh.exe", "interface", "portproxy", "delete", "v4tov4", "listenaddress="+address, "listenport="+port)
	}
}

// trust adds the shop's certificate authority to Windows' trusted roots, so Chrome opens the server with
// no warning; it replaces the setup page's one line.
func trust(ctx context.Context, out io.Writer, pem []byte) error {
	if !bytes.HasPrefix(bytes.TrimSpace(pem), []byte("-----BEGIN CERTIFICATE-----")) {
		return errors.New("the shop's certificate is not a certificate")
	}
	file := filepath.Join(installDir(), "haq-shop-ca.crt")
	if err := os.WriteFile(file, pem, 0o644); err != nil {
		return err
	}
	defer os.Remove(file)
	return run(ctx, out, "certutil.exe", "-addstore", "-f", "Root", file)
}

func untrust(ctx context.Context) {
	_ = run(ctx, io.Discard, "certutil.exe", "-delstore", "Root", caSubject)
}

// startupShortcut is the counter's start-up shortcut, for everyone who signs in to this PC.
func startupShortcut() string {
	return filepath.Join(os.Getenv("ProgramData"), `Microsoft\Windows\Start Menu\Programs\StartUp\HAQ Retail OS.lnk`)
}

// browser finds Chrome, or else Microsoft Edge, which is the same engine and comes with Windows, with the
// arguments that open url full screen.
func browser(url string) (exe, args string, err error) {
	for _, base := range []string{os.Getenv("ProgramFiles"), os.Getenv("ProgramFiles(x86)")} {
		chrome := filepath.Join(base, `Google\Chrome\Application\chrome.exe`)
		if _, err := os.Stat(chrome); err == nil {
			return chrome, "--kiosk " + url + " --no-first-run --noerrdialogs", nil
		}
	}
	for _, base := range []string{os.Getenv("ProgramFiles(x86)"), os.Getenv("ProgramFiles")} {
		edge := filepath.Join(base, `Microsoft\Edge\Application\msedge.exe`)
		if _, err := os.Stat(edge); err == nil {
			return edge, "--kiosk " + url + " --edge-kiosk-type=fullscreen --no-first-run", nil
		}
	}
	return "", "", errors.New("neither Chrome nor Edge is installed: install Chrome, then run the setup again")
}

// counterShortcut opens the counter full screen whenever someone signs in to this PC.
func counterShortcut(ctx context.Context, out io.Writer, server string) error {
	exe, args, err := browser("https://" + server + "/counter")
	if err != nil {
		return err
	}
	return ps(ctx, out, `
$shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut($env:HAQ_LINK)
$shortcut.TargetPath = $env:HAQ_EXE
$shortcut.Arguments = $env:HAQ_ARGS
$shortcut.Description = 'HAQ Retail OS: the counter, full screen'
$shortcut.Save()`, map[string]string{"HAQ_LINK": startupShortcut(), "HAQ_EXE": exe, "HAQ_ARGS": args})
}

// neverSleep keeps a PC on the mains awake, so a counter or the server is never asleep in a queue.
func neverSleep(ctx context.Context) {
	_ = run(ctx, io.Discard, "powercfg.exe", "/change", "standby-timeout-ac", "0")
	_ = run(ctx, io.Discard, "powercfg.exe", "/change", "hibernate-timeout-ac", "0")
}

// wslInstalled says whether Windows' Linux (WSL) is ready to use.
func wslInstalled(ctx context.Context) bool {
	cmd := exec.CommandContext(ctx, "wsl.exe", "--version")
	cmd.Env = append(os.Environ(), "WSL_UTF8=1")
	return cmd.Run() == nil
}
