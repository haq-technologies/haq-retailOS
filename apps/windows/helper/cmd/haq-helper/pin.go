package main

import (
	"errors"
	"slices"
	"strings"
)

// On Ubuntu, `sudo haq add-user` and `sudo haq pin` ask for the PIN in the terminal. On Windows the server's
// commands reach it through the runner, which has no keyboard (D91), so the helper asks for the PIN here,
// hidden and twice, and sends it on standard input with --pin-stdin, so it never stands in a command line
// (D99). The runner's queue, which then holds it, is deleted as soon as the command ends.

// askSecret reads a line with nothing shown as it is typed; secret_windows.go and secret_other.go.
type askSecret func(prompt string) (string, error)

// withPIN gives `server add-user <name> <role>` and `server pin <name>` their PIN, asked here, unless one
// is already given: the arguments with --pin-stdin, and the PIN as the command's input. Any other command,
// or one missing its name, goes on as it is: haq says what is wrong.
func withPIN(args []string, ask askSecret) ([]string, string, error) {
	if len(args) == 0 {
		return args, "", nil
	}
	var hint string
	switch {
	case args[0] == "add-user" && len(args) >= 3:
		hint = pinHint(args[2])
	case args[0] == "pin" && len(args) >= 2:
		hint = "4 to 6 digits; 6 for a manager or the owner"
	default:
		return args, "", nil
	}
	for _, a := range args[1:] {
		if a == "--pin" || strings.HasPrefix(a, "--pin=") || a == "--pin-stdin" {
			return args, "", nil
		}
	}
	pin, err := ask("PIN for " + args[1] + " (" + hint + "): ")
	if err != nil {
		return nil, "", err
	}
	again, err := ask("The same PIN again: ")
	if err != nil {
		return nil, "", err
	}
	if pin != again {
		return nil, "", errors.New("the two PINs differ: nothing was changed")
	}
	if pin == "" || strings.Trim(pin, "0123456789") != "" {
		return nil, "", errors.New("a PIN is digits only: nothing was changed")
	}
	return append(slices.Clone(args), "--pin-stdin"), pin + "\n", nil
}

// pinHint is what the server asks of a role's PIN (app/Identity/Role.php): 6 digits for those who approve.
func pinHint(role string) string {
	switch strings.ToLower(role) {
	case "manager", "owner":
		return "6 digits"
	}
	return "4 to 6 digits"
}
