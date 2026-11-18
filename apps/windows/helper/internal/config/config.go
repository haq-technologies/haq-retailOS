// Package config is the HAQ Helper's settings on a PC (D86): C:\ProgramData\HAQ\helper.json, which the setup
// writes and the service and the keep-alive read.
package config

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
)

// Mode is what the PC is to the shop.
type Mode string

const (
	// Server: this PC runs the shop server, hidden in WSL2, and is its first counter.
	Server Mode = "server"
	// Counter: this PC is a counter of a shop whose server is another PC.
	Counter Mode = "counter"
)

const (
	// DefaultDistribution is the WSL distribution the server runs in.
	DefaultDistribution = "haq-server"
	// DefaultListen is where the print bridge listens: the port a network receipt printer has.
	DefaultListen = ":9100"
)

// Config is one PC's settings.
type Config struct {
	Mode Mode `json:"mode"`
	// Address is this PC's fixed IPv4 address on the shop's network.
	Address string `json:"address"`
	// Server is the shop server's address: this PC's own in server mode.
	Server string `json:"server"`
	// Distribution is the WSL distribution the server runs in, in server mode.
	Distribution string `json:"distribution,omitempty"`
	// Printer is the receipt printer this PC's bridge serves, as `haq-helper printers` names it; empty when
	// the PC's counter prints to a network printer, so it has no bridge.
	Printer string `json:"printer,omitempty"`
	// Listen is where the bridge listens.
	Listen string `json:"listen,omitempty"`
}

var distribution = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)

// Dir is the helper's folder: %ProgramData%\HAQ, or HAQ_HOME for tests.
func Dir() string {
	if d := os.Getenv("HAQ_HOME"); d != "" {
		return d
	}
	data := os.Getenv("ProgramData")
	if data == "" {
		data = `C:\ProgramData`
	}
	return filepath.Join(data, "HAQ")
}

// Path is the settings file.
func Path() string { return filepath.Join(Dir(), "helper.json") }

// StatusDir holds the PC's report for the health page.
func StatusDir() string { return filepath.Join(Dir(), "status") }

// LogDir holds the service's and the keep-alive's logs.
func LogDir() string { return filepath.Join(Dir(), "logs") }

func (c *Config) defaults() {
	if c.Mode == Server && c.Distribution == "" {
		c.Distribution = DefaultDistribution
	}
	if c.Listen == "" {
		c.Listen = DefaultListen
	}
}

// Validate says what is wrong with the settings, if anything.
func (c Config) Validate() error {
	if c.Mode != Server && c.Mode != Counter {
		return fmt.Errorf("the mode is %q: it must be server or counter", c.Mode)
	}
	address, err := netip.ParseAddr(c.Address)
	if err != nil || !address.Is4() {
		return fmt.Errorf("this PC's address %q is not an IPv4 address", c.Address)
	}
	server, err := netip.ParseAddr(c.Server)
	if err != nil || !server.Is4() {
		return fmt.Errorf("the server's address %q is not an IPv4 address", c.Server)
	}
	if c.Mode == Server {
		if server != address {
			return errors.New("in server mode the server's address is this PC's own")
		}
		if !distribution.MatchString(c.Distribution) {
			return fmt.Errorf("the distribution's name %q is not a WSL distribution name", c.Distribution)
		}
	}
	if _, _, err := net.SplitHostPort(c.Listen); err != nil {
		return fmt.Errorf("the bridge's listen address %q: %w", c.Listen, err)
	}
	return nil
}

// Load reads and checks the settings. A field it does not know is an error, so a mistyped one is never
// silently ignored.
func Load(path string) (Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return Config{}, err
	}
	var c Config
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&c); err != nil {
		return Config{}, fmt.Errorf("%s: %w", path, err)
	}
	c.defaults()
	if err := c.Validate(); err != nil {
		return Config{}, fmt.Errorf("%s: %w", path, err)
	}
	return c, nil
}

// Save checks the settings and writes them whole: a reader never sees half a file.
func Save(path string, c Config) error {
	c.defaults()
	if err := c.Validate(); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return WriteFile(path, append(raw, '\n'))
}

// WriteFile writes a file whole, through a temporary file beside it.
func WriteFile(path string, data []byte) error {
	tmp, err := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+".*.tmp")
	if err != nil {
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		os.Remove(tmp.Name())
		return err
	}
	if err := tmp.Close(); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	if err := os.Rename(tmp.Name(), path); err != nil {
		os.Remove(tmp.Name())
		return err
	}
	return nil
}
