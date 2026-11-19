package main

import (
	"errors"
	"slices"
	"strings"
	"testing"
)

// typed stands in for the person at the keyboard: each answer in turn, and the prompts they saw.
type typed struct {
	answers []string
	prompts []string
}

func (k *typed) ask(prompt string) (string, error) {
	k.prompts = append(k.prompts, prompt)
	if len(k.answers) == 0 {
		return "", errors.New("nothing more was typed")
	}
	a := k.answers[0]
	k.answers = k.answers[1:]
	return a, nil
}

func TestAddingAPersonAsksTheirPINTwiceAndPassesItOn(t *testing.T) {
	k := &typed{answers: []string{"1357", "1357"}}
	got, err := withPIN([]string{"add-user", "Amina", "cashier"}, k.ask)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"add-user", "Amina", "cashier", "--pin=1357"}; !slices.Equal(got, want) {
		t.Fatalf("got %q, want %q", got, want)
	}
	if len(k.prompts) != 2 || !strings.Contains(k.prompts[0], "PIN for Amina (4 to 6 digits)") {
		t.Fatalf("prompts %q", k.prompts)
	}
}

func TestAManagersPINIsAskedAsSixDigits(t *testing.T) {
	k := &typed{answers: []string{"246810", "246810"}}
	if _, err := withPIN([]string{"add-user", "Kemi", "Manager"}, k.ask); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(k.prompts[0], "(6 digits)") {
		t.Fatalf("prompt %q", k.prompts[0])
	}
}

func TestANewPINIsAskedForTheNameGiven(t *testing.T) {
	k := &typed{answers: []string{"9753", "9753"}}
	got, err := withPIN([]string{"pin", "Amina"}, k.ask)
	if err != nil || !slices.Equal(got, []string{"pin", "Amina", "--pin=9753"}) {
		t.Fatalf("got %q, %v", got, err)
	}
}

func TestTwoDifferentPINsChangeNothing(t *testing.T) {
	_, err := withPIN([]string{"add-user", "Amina", "cashier"}, (&typed{answers: []string{"1357", "1358"}}).ask)
	if err == nil || !strings.Contains(err.Error(), "differ") {
		t.Fatalf("got %v", err)
	}
}

func TestAPINIsDigitsOnly(t *testing.T) {
	for _, pin := range []string{"", "12a4", "12 34"} {
		if _, err := withPIN([]string{"pin", "Amina"}, (&typed{answers: []string{pin, pin}}).ask); err == nil {
			t.Fatalf("%q was taken", pin)
		}
	}
}

func TestAGivenPINAndOtherCommandsAreNotAsked(t *testing.T) {
	for _, args := range [][]string{
		{"add-user", "Amina", "cashier", "--pin=1357"},
		{"pin", "Amina", "--pin", "1357"},
		{"add-user", "Amina"}, // haq itself says the role is missing
		{"pin"},
		{"enrol", "T02"},
		{"users"},
		{},
	} {
		k := &typed{}
		got, err := withPIN(args, k.ask)
		if err != nil || !slices.Equal(got, args) || len(k.prompts) != 0 {
			t.Fatalf("%q: got %q, %v, asked %q", args, got, err, k.prompts)
		}
	}
}

func TestNothingTypedStopsIt(t *testing.T) {
	if _, err := withPIN([]string{"pin", "Amina"}, (&typed{}).ask); err == nil {
		t.Fatal("it went on with no PIN")
	}
}
