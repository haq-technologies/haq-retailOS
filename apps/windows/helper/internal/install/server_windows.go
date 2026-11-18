//go:build windows

package install

import (
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/service"
)

// Server installs the shop on this PC: the hidden HAQServer account, the haq-server distribution imported
// under it, the shop server installed inside from the distribution's own installer, its ports opened to
// the shop's network, its certificate trusted, the HAQ Helper started, and this PC made counter T01.
func Server(ctx context.Context, a Answers, distro string, out io.Writer) (Result, error) {
	if a.Mode != config.Server {
		return Result{}, errors.New("these answers are for a counter")
	}
	if _, err := os.Stat(filepath.Join(config.Dir(), installedMark)); err == nil {
		return Result{}, errors.New("this PC runs the shop already: the setup updates it instead")
	}
	s := steps{out: out, of: 10}
	dist := config.DefaultDistribution

	s.next("Checking this PC")
	if err := Preflight(ctx, out, config.Server, a.Address); err != nil {
		return Result{}, err
	}
	if _, err := os.Stat(distro); err != nil {
		return Result{}, fmt.Errorf("the server's Linux is missing from the setup (%s)", distro)
	}

	s.next("Preparing the hidden " + account + " account and the folders")
	if err := prepare(ctx, out); err != nil {
		return Result{}, err
	}
	cfg := config.Config{Mode: config.Server, Address: a.Address, Server: a.Address, Printer: a.Printer}
	if err := config.Save(config.Path(), cfg); err != nil {
		return Result{}, err
	}

	s.next("Importing the server's Linux (a few minutes)")
	if err := importDistribution(ctx, out, distro, dist); err != nil {
		return Result{}, err
	}

	s.next("Starting it")
	if err := startTask(ctx, serverTask); err != nil {
		return Result{}, err
	}
	if err := waitForDocker(ctx, out, dist); err != nil {
		return Result{}, err
	}

	s.next(fmt.Sprintf("Installing the shop server, release %s (10 to 30 minutes, most of it downloading)", a.Tag))
	if err := installInside(ctx, out, a, dist); err != nil {
		return Result{}, err
	}

	s.next("Opening the shop to its network")
	removeForward(ctx, a.Address) // a first install that stopped may have made it; the ports are checked without it
	if err := portsFree(a.Address); err != nil {
		return Result{}, err
	}
	bridgeFrom := ""
	if a.Printer != "" {
		bridgeFrom = "LocalSubnet" // the server inside WSL; the bridge itself lets in only WSL's own network
	}
	if err := forward(ctx, out, a.Address); err != nil {
		return Result{}, err
	}
	if err := firewall(ctx, out, true, bridgeFrom); err != nil {
		return Result{}, err
	}

	s.next("Trusting the shop's certificate on this PC")
	var printed bytes.Buffer
	if err := inside(ctx, &printed, dist, "/usr/local/bin/haq", "certificate", "--file"); err != nil {
		return Result{}, err
	}
	ca, err := certificate(printed.Bytes())
	if err != nil {
		return Result{}, err
	}
	if err := trust(ctx, out, ca); err != nil {
		return Result{}, err
	}
	thumb, _ := thumbprint(ca)
	fmt.Fprintln(out, "The shop's certificate:", thumb)

	s.next("Starting the HAQ Helper")
	if err := startService(); err != nil {
		return Result{}, err
	}

	s.next("Making this PC counter T01")
	var enrolled bytes.Buffer
	if err := inside(ctx, &enrolled, dist, "/usr/local/bin/haq", "artisan", "shop:enrol-terminal", "T01"); err != nil {
		return Result{}, err
	}
	code, err := enrolCodeFrom(enrolled.String())
	if err != nil {
		return Result{}, err
	}
	if err := counterShortcut(ctx, out, a.Address); err != nil {
		return Result{}, err
	}
	neverSleep(ctx)
	mark := fmt.Sprintf("release %s, %s\n", a.Tag, time.Now().UTC().Format(time.RFC3339))
	if err := config.WriteFile(filepath.Join(config.Dir(), installedMark), []byte(mark)); err != nil {
		return Result{}, err
	}
	fmt.Fprintf(out, "\nThe shop is installed. It answers at https://%s from every device on the shop's network.\n", a.Address)
	return Result{EnrolURL: "https://" + a.Address + "/enrol#code=" + code, Thumbprint: thumb}, nil
}

// prepare makes (or refreshes) HAQServer with a new password, its folders, and its two tasks; the password
// is given to Windows for the tasks and then forgotten.
func prepare(ctx context.Context, out io.Writer) error {
	password := newPassword()
	if err := ensureAccount(ctx, out, password); err != nil {
		return err
	}
	if err := folders(ctx, out, true); err != nil {
		return err
	}
	if err := registerTask(ctx, out, runnerTask, "run-queued", false, password); err != nil {
		return err
	}
	return registerTask(ctx, out, serverTask, "keepalive", true, password)
}

func importDistribution(ctx context.Context, out io.Writer, distro, dist string) error {
	var listed bytes.Buffer
	if _, err := asServerHere(ctx, &listed, "wsl.exe", "--list", "--quiet"); err != nil {
		return err
	}
	for _, name := range distributions(listed.String()) {
		if strings.EqualFold(name, dist) {
			fmt.Fprintln(out, "It is imported already.")
			return nil
		}
	}
	tar := filepath.Join(installDir(), "haq-server.tar")
	if err := unpack(distro, tar); err != nil {
		return fmt.Errorf("unpacking the server's Linux: %w", err)
	}
	defer os.Remove(tar)
	code, err := asServerHere(ctx, out, "wsl.exe", "--import", dist, filepath.Join(config.Dir(), "wsl"), tar, "--version", "2")
	if err != nil {
		return err
	}
	if code != 0 {
		return fmt.Errorf("WSL could not import the server's Linux (code %d); the output above says why", code)
	}
	return nil
}

// portsFree checks no other program holds the PC's ports 80 and 443, which the shop's forward needs: IIS,
// another web server, or a program that took them.
func portsFree(address string) error {
	for _, port := range []string{"80", "443"} {
		ln, err := net.Listen("tcp", net.JoinHostPort(address, port))
		if err != nil {
			return fmt.Errorf("another program on this PC holds port %s, which the shop needs (%v): stop it, or ask HAQ support", port, err)
		}
		ln.Close()
	}
	return nil
}

// unpack writes the distribution as a plain tar, which WSL imports on every Windows 10 and 11.
func unpack(from, to string) error {
	in, err := os.Open(from)
	if err != nil {
		return err
	}
	defer in.Close()
	var r io.Reader = in
	if strings.HasSuffix(strings.ToLower(from), ".gz") {
		z, err := gzip.NewReader(in)
		if err != nil {
			return err
		}
		defer z.Close()
		r = z
	}
	f, err := os.Create(to)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, r); err != nil {
		f.Close()
		return err
	}
	return f.Close()
}

// waitForDocker waits for systemd to start Docker in the distribution the keep-alive has just started.
func waitForDocker(ctx context.Context, out io.Writer, dist string) error {
	deadline := time.Now().Add(5 * time.Minute)
	for {
		var state bytes.Buffer
		code, err := asServerHere(ctx, &state, "wsl.exe", "-d", dist, "-u", "root", "-e", "/usr/bin/systemctl", "is-active", "docker")
		if err != nil {
			return err
		}
		if code == 0 && strings.TrimSpace(state.String()) == "active" {
			fmt.Fprintln(out, "Docker is running inside it.")
			return nil
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("Docker did not start inside it within 5 minutes: %s", strings.TrimSpace(state.String()))
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(5 * time.Second):
		}
	}
}

// installInside leaves the install's files for windows-install.sh, which deletes the token and the PIN as
// soon as it has read them; whatever is left goes once it is done.
func installInside(ctx context.Context, out io.Writer, a Answers, dist string) error {
	profile, err := a.Profile()
	if err != nil {
		return err
	}
	dir := installDir()
	files := map[string][]byte{
		"profile.json": profile, "ghcr_user": []byte(a.GHCRUser), "ghcr_token": []byte(a.GHCRToken),
		"owner_name": []byte(strings.TrimSpace(a.OwnerName)), "owner_pin": []byte(a.OwnerPIN),
	}
	var paths []string
	for name := range files {
		paths = append(paths, filepath.Join(dir, name))
	}
	defer removeAll(paths...)
	for name, data := range files {
		if err := os.WriteFile(filepath.Join(dir, name), data, 0o600); err != nil {
			return err
		}
	}
	from, err := wslPath(dir)
	if err != nil {
		return err
	}
	return inside(ctx, out, dist, "/bin/bash", "/opt/haq-install/windows-install.sh", a.Address, a.Tag, from)
}

// startService installs the HAQ Helper service, or points the one there at this program, and starts it
// afresh so it reads the new settings.
func startService() error {
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	if err := service.Install(exe, "service"); err != nil {
		return fmt.Errorf("installing the HAQ Helper service: %w", err)
	}
	if err := service.Stop(); err != nil {
		return err
	}
	return service.Start()
}
