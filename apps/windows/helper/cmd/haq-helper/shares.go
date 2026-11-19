package main

import (
	"errors"
	"slices"
	"strings"
)

// A restore from an off-site drive needs two of the three key shares (D53). On Ubuntu, restore.sh asks for
// them in the terminal. On Windows the runner has no terminal (D91), so the helper asks for them here, hidden,
// and sends them as the command's input, never in a command line (D101).

// restoreShares gives `server restore --from-windows-drive <letter> <A|B>` (or --from-drive) the two shares,
// asked here, as the input to send. Any other command needs none.
func restoreShares(args []string, ask askSecret) (string, error) {
	if len(args) == 0 || args[0] != "restore" ||
		(!slices.Contains(args, "--from-windows-drive") && !slices.Contains(args, "--from-drive")) {
		return "", nil
	}
	first, err := ask("First key share (it starts with haq-): ")
	if err != nil {
		return "", err
	}
	second, err := ask("Second key share: ")
	if err != nil {
		return "", err
	}
	first, second = strings.TrimSpace(first), strings.TrimSpace(second)
	for _, share := range []string{first, second} {
		if !strings.HasPrefix(share, "haq-") || strings.ContainsAny(share, " \t") {
			return "", errors.New("a key share starts with haq- and has no spaces: nothing was restored")
		}
	}
	if first == second {
		return "", errors.New("give two different shares, from two of the three people who hold them: nothing was restored")
	}
	return first + "\n" + second + "\n", nil
}
