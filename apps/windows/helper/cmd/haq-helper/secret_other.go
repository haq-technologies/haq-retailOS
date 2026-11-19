//go:build !windows

package main

import "errors"

// readSecret: the server's commands run only on Windows, so elsewhere there is nothing to ask for.
func readSecret(string) (string, error) {
	return "", errors.New("the shop server's commands run on its Windows PC")
}
