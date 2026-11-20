//go:build windows

package install

import (
	"errors"
	"fmt"
	"io"

	"golang.org/x/sys/windows/registry"
)

// Windows Update's active hours (D98): Windows never restarts a shop PC for an update between 6:00 and 23:00,
// so an update never stops the server or a counter while the shop trades. Windows allows at most 18 hours.
const (
	activeFrom = 6
	activeTo   = 23
)

const (
	// The active hours the Settings app shows, which every edition keeps, Home included.
	updateSettingsKey = `SOFTWARE\Microsoft\WindowsUpdate\UX\Settings`
	// The policy, which Pro and the editions above it keep even if someone changes the setting.
	updatePolicyKey = `SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate`
)

// activeHours sets the shop's active hours, and says so; a PC that refuses is told in the window and the
// install goes on, since the shop can still trade.
func activeHours(out io.Writer) {
	err := errors.Join(
		setDWords(updateSettingsKey, map[string]uint32{
			"ActiveHoursStart": activeFrom, "ActiveHoursEnd": activeTo,
			// Windows' own guess at the hours, from when the PC is used, would replace these.
			"SmartActiveHoursState": 0,
		}),
		setDWords(updatePolicyKey, map[string]uint32{
			"SetActiveHours": 1, "ActiveHoursStart": activeFrom, "ActiveHoursEnd": activeTo,
		}),
	)
	if err != nil {
		fmt.Fprintf(out, "WARNING: Windows Update's active hours could not be set (%v). Set them in Settings, Windows Update: %d:00 to %d:00.\n", err, activeFrom, activeTo)
		return
	}
	fmt.Fprintf(out, "Windows Update restarts this PC only outside %d:00 to %d:00.\n", activeFrom, activeTo)
}

// removeActiveHours takes the policy away when HAQ is uninstalled; the setting stays, as the PC's owner may
// keep or change it in Settings.
func removeActiveHours() error {
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, updatePolicyKey, registry.SET_VALUE)
	if errors.Is(err, registry.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	defer key.Close()
	for _, name := range []string{"SetActiveHours", "ActiveHoursStart", "ActiveHoursEnd"} {
		if err := key.DeleteValue(name); err != nil && !errors.Is(err, registry.ErrNotExist) {
			return err
		}
	}
	return nil
}

func setDWords(path string, values map[string]uint32) error {
	key, _, err := registry.CreateKey(registry.LOCAL_MACHINE, path, registry.SET_VALUE)
	if err != nil {
		return fmt.Errorf(`HKLM\%s: %w`, path, err)
	}
	defer key.Close()
	for name, value := range values {
		if err := key.SetDWordValue(name, value); err != nil {
			return fmt.Errorf(`HKLM\%s\%s: %w`, path, name, err)
		}
	}
	return nil
}
