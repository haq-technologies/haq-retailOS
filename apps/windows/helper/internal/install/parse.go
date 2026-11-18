package install

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"strings"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// Result is what the setup program shows and does once the engine is done: the page that registers this
// PC as a counter, opened in the browser as the person who ran the setup, and the line to run on the
// server's PC for this counter's printer.
type Result struct {
	EnrolURL    string `json:"enrol_url,omitempty"`
	PrinterLine string `json:"printer_line,omitempty"`
	Thumbprint  string `json:"thumbprint,omitempty"`
}

// Write keeps the result where the setup program reads it.
func (r Result) Write(path string) error {
	if path == "" {
		return nil
	}
	raw, err := json.MarshalIndent(r, "", "  ")
	if err != nil {
		return err
	}
	return config.WriteFile(path, append(raw, '\n'))
}

// certificate takes the shop's certificate out of what `haq certificate --file` printed, which may carry
// Docker's warnings around it.
func certificate(out []byte) ([]byte, error) {
	rest := out
	for {
		block, next := pem.Decode(rest)
		if block == nil {
			return nil, errors.New("the server gave no certificate")
		}
		if block.Type == "CERTIFICATE" {
			return pem.EncodeToMemory(block), nil
		}
		rest = next
	}
}

// thumbprint is a certificate's SHA-1 thumbprint as Windows and the setup page show it: "AB12 CD34 ...".
func thumbprint(certPEM []byte) (string, error) {
	block, _ := pem.Decode(certPEM)
	if block == nil || block.Type != "CERTIFICATE" {
		return "", errors.New("not a certificate")
	}
	sum := sha1.Sum(block.Bytes)
	hexed := strings.ToUpper(hex.EncodeToString(sum[:]))
	var groups []string
	for i := 0; i < len(hexed); i += 4 {
		groups = append(groups, hexed[i:i+4])
	}
	return strings.Join(groups, " "), nil
}

// sameThumbprint compares two thumbprints however they are spaced or cased.
func sameThumbprint(a, b string) bool {
	norm := func(s string) string {
		return strings.ToUpper(strings.Join(strings.Fields(strings.ReplaceAll(s, ":", " ")), ""))
	}
	return norm(a) != "" && norm(a) == norm(b)
}

// enrolCodeFrom takes the one-time code from shop:enrol-terminal's output: its last line.
func enrolCodeFrom(out string) (string, error) {
	lines := strings.Split(strings.TrimSpace(out), "\n")
	code := strings.TrimSpace(lines[len(lines)-1])
	if !enrolCode.MatchString(code) {
		return "", fmt.Errorf("the server gave no enrolment code:\n%s", strings.TrimSpace(out))
	}
	return code, nil
}

// distributions reads `wsl --list --quiet`: one name a line.
func distributions(out string) []string {
	var names []string
	for _, line := range strings.Split(out, "\n") {
		if name := strings.TrimSpace(strings.Trim(line, "\x00\ufeff")); name != "" {
			names = append(names, name)
		}
	}
	return names
}

func removeAll(paths ...string) {
	for _, p := range paths {
		_ = os.Remove(p)
	}
}
