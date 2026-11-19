//go:build windows

package main

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"os/signal"
	"strings"

	"golang.org/x/sys/windows"
)

var stdin = bufio.NewReader(os.Stdin)

// readSecret asks in the console with echo off, as `sudo haq` does on Ubuntu, and puts the console back
// as it was, even on Ctrl+C.
func readSecret(prompt string) (string, error) {
	h := windows.Handle(os.Stdin.Fd())
	var mode uint32
	if err := windows.GetConsoleMode(h, &mode); err != nil {
		return "", errors.New("there is no keyboard here to type the PIN: run it in Terminal (Admin), or give --pin=")
	}
	fmt.Fprint(os.Stderr, prompt)
	if err := windows.SetConsoleMode(h, mode&^windows.ENABLE_ECHO_INPUT); err != nil {
		return "", err
	}
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt)
	go func() {
		if _, ok := <-stop; ok {
			_ = windows.SetConsoleMode(h, mode)
			fmt.Fprintln(os.Stderr)
			os.Exit(130)
		}
	}()
	line, err := stdin.ReadString('\n')
	signal.Stop(stop)
	close(stop)
	_ = windows.SetConsoleMode(h, mode)
	fmt.Fprintln(os.Stderr)
	if err != nil && line == "" {
		return "", err
	}
	return strings.TrimRight(line, "\r\n"), nil
}
