package install

import "fmt"

// Which Windows the shop's server runs on (D92). It needs Microsoft's current WSL, which brings systemd and
// `wsl --version`. Microsoft gives that to Windows 10 from 20H2 (build 19042) to 22H2 (19045) once it has
// the November 2022 update (KB5020030, revision 2311) or a later one, and to every Windows 11. A counter
// installs no Linux, so it takes any 64-bit Windows 10 or 11.
const (
	firstServerBuild    = 19042
	firstServerRevision = 2311
	lastWindows10Build  = 19045
)

// serverWindowsProblem says what stops this Windows from running the shop's server, and what to do about it;
// "" when nothing does. version is Windows' own name for it, such as 21H1, when it has one.
func serverWindowsProblem(build, revision uint32, version string) string {
	if build > lastWindows10Build || (build >= firstServerBuild && revision >= firstServerRevision) {
		return ""
	}
	name := "Windows 10"
	if version != "" {
		name += " version " + version
	}
	return fmt.Sprintf("This PC has %s (build %d.%d), which is too old to run the shop's server.\n\n"+
		"Update Windows first, which is free: open Settings, then Update & Security, then Windows Update, and "+
		"install every update it offers, including the feature update to Windows 10, version 22H2. Then run "+
		"this setup again.\n\nThis PC can be a counter as it is.", name, build, revision)
}

// lowMemoryGB is the least memory the server is tested with: 8 GB, which Windows reports as a little under.
const lowMemoryGB = 7

// LowMemoryError says the PC has less memory than the server is tested with. It is a warning, not a stop
// (D92): the setup's wizard asks whether to go on, and the install goes on past it, with the warning in its
// log, until a measurement sets the least the server needs.
type LowMemoryError struct{ GB float64 }

func (e LowMemoryError) Error() string {
	return fmt.Sprintf("This PC has %.1f GB of memory. The shop's server is tested with 8 GB; with less, the "+
		"counter may be slow at busy times.", e.GB)
}

// The words for the stops the setup cannot fix by itself.
const (
	virtualisationOff = "Virtualisation is turned off in this PC's firmware (its BIOS or UEFI), and the shop's " +
		"server needs it.\n\nRestart the PC and open its firmware setup: the key is shown as it starts, often " +
		"F2, F10, Del or Esc. Turn on Intel Virtualization Technology (VT-x), or SVM Mode or AMD-V on an AMD " +
		"processor, save, and run this setup again.\n\nThis PC can be a counter as it is."
	armProcessor = "This PC has an ARM processor. It can be a counter, but the shop's server needs a PC with " +
		"an Intel or AMD processor."
	tooOldWindows = "HAQ Retail OS needs Windows 10 or 11. This PC has an older Windows."
)
