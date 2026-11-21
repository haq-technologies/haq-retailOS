// Package support makes the file a PC gives HAQ support (finding LG7): one ZIP a person puts on a USB drive
// or sends on WhatsApp, since there is no internet to look at the shop from. On every PC it holds the HAQ
// Helper's logs and the PC's own report; on the server's PC it holds the server's own support file too, made
// inside its Linux by `haq support-file`. It never holds config.json, the install folder (the setup's answers
// and the GHCR token), the distribution or the runner's queue.
package support

import (
	"archive/zip"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

// Files is what goes in.
type Files struct {
	// Root is C:\ProgramData\HAQ.
	Root string
	// ServerZip is the server's own support file, on the server's PC; empty elsewhere.
	ServerZip string
	Version   string
	Mode      string
	Now       time.Time
}

var redactions = []struct {
	pattern *regexp.Regexp
	with    string
}{
	{regexp.MustCompile(`\$argon2(?:id|i|d)\$[^\s"'\\,)]+`), "[a PIN hash, left out]"},
	{regexp.MustCompile(`base64:[A-Za-z0-9+/]{20,}={0,2}`), "[a key, left out]"},
	{regexp.MustCompile(`\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}`), "[a token, left out]"},
	{regexp.MustCompile(`(?i)\b(password|passwd|secret|token)(["']?\s*[=:]\s*["']?)[^\s"',;&]+`), "${1}${2}[left out]"},
}

// Redact takes out what must never leave the shop, should a log ever hold it: a PIN's hash, a key, a token or a
// password. The logs are kept clean of them already; this is the second line.
func Redact(b []byte) []byte {
	for _, r := range redactions {
		b = r.pattern.ReplaceAll(b, []byte(r.with))
	}
	return b
}

// WSLPath is a Windows path as the server's Linux sees it: C:\ProgramData\HAQ is /mnt/c/ProgramData/HAQ.
func WSLPath(windows string) (string, error) {
	if len(windows) < 3 || windows[1] != ':' || (windows[2] != '\\' && windows[2] != '/') {
		return "", fmt.Errorf("%q is not a full Windows path", windows)
	}
	drive := strings.ToLower(windows[:1])
	if drive < "a" || drive > "z" {
		return "", fmt.Errorf("%q has no drive letter", windows)
	}
	return "/mnt/" + drive + "/" + strings.ReplaceAll(windows[3:], `\`, "/"), nil
}

// Write makes the ZIP at path. It refuses to write over a file that is there already.
func Write(path string, f Files) (err error) {
	out, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	defer func() {
		if cerr := out.Close(); err == nil {
			err = cerr
		}
		if err != nil {
			_ = os.Remove(path)
		}
	}()
	zw := zip.NewWriter(out)

	about := fmt.Sprintf("HAQ Retail OS support file\nMade: %s\nThis PC: %s\nHAQ Helper: %s\n", f.Now.UTC().Format(time.RFC3339), f.Mode, f.Version)
	if err := add(zw, "about.txt", f.Now, []byte(about)); err != nil {
		return err
	}

	logs, err := filepath.Glob(filepath.Join(f.Root, "logs", "*"))
	if err != nil {
		return err
	}
	sort.Strings(logs)
	for _, name := range logs {
		if info, err := os.Stat(name); err != nil || !info.Mode().IsRegular() {
			continue
		}
		b, err := os.ReadFile(name)
		if err != nil {
			return err
		}
		if err := add(zw, "helper/logs/"+filepath.Base(name), f.Now, Redact(b)); err != nil {
			return err
		}
	}

	if b, err := os.ReadFile(filepath.Join(f.Root, "status", "host.json")); err == nil {
		if err := add(zw, "helper/status/host.json", f.Now, Redact(b)); err != nil {
			return err
		}
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}

	if f.ServerZip != "" {
		b, err := os.ReadFile(f.ServerZip)
		if err != nil {
			return fmt.Errorf("the server's own support file: %w", err)
		}
		// Made and redacted by the server; kept whole.
		if err := add(zw, "server/haq-support.zip", f.Now, b); err != nil {
			return err
		}
	}
	return zw.Close()
}

func add(zw *zip.Writer, name string, at time.Time, b []byte) error {
	w, err := zw.CreateHeader(&zip.FileHeader{Name: name, Method: zip.Deflate, Modified: at.UTC()})
	if err != nil {
		return err
	}
	_, err = w.Write(b)
	return err
}
