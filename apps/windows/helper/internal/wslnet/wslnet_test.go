package wslnet

import (
	"net/netip"
	"testing"
	"time"
)

var shopPC = []Adapter{
	{Name: "Wi-Fi", Prefixes: []netip.Prefix{netip.MustParsePrefix("10.92.139.200/24")}},
	{Name: "vEthernet (WSL (Hyper-V firewall))", Prefixes: []netip.Prefix{netip.MustParsePrefix("172.24.16.1/20")}},
	{Name: "Loopback Pseudo-Interface 1", Prefixes: []netip.Prefix{netip.MustParsePrefix("127.0.0.1/8")}},
}

func TestOnlyWSLsNetworkIsWSLs(t *testing.T) {
	got := Networks(shopPC)
	if len(got) != 1 || got[0] != netip.MustParsePrefix("172.24.16.0/20") {
		t.Fatalf("got %v", got)
	}
	if !IsWSL("vEthernet (WSL)") || IsWSL("vEthernet (Default Switch)") {
		t.Fatal("Windows 10's name is WSL's; the Default Switch is not")
	}
}

func TestTheBridgeLetsInTheServerInsideWSLAndNoOneElse(t *testing.T) {
	allow := Allow(func() ([]Adapter, error) { return shopPC, nil }, time.Minute)
	for ip, want := range map[string]bool{
		"172.24.20.7": true, // the server's VM
		// The PC's own address: on some PCs the server's receipts come from it (D144).
		"10.92.139.200": true,
		"127.0.0.1":     true,
		"10.92.139.37":  false, // a phone on the hotspot, on the PC's own network
		"10.92.139.201": false, // the next address along
	} {
		if got := allow(netip.MustParseAddr(ip)); got != want {
			t.Errorf("%s: %v, want %v", ip, got, want)
		}
	}
}

func TestANetworkWSLHasJustMadeIsLetInAtOnce(t *testing.T) {
	retry = 0
	t.Cleanup(func() { retry = time.Second })
	now := []Adapter{{Name: "vEthernet (WSL)", Prefixes: []netip.Prefix{netip.MustParsePrefix("172.24.16.1/20")}}}
	looks := 0
	allow := Allow(func() ([]Adapter, error) { looks++; return now, nil }, time.Hour)
	if !allow(netip.MustParseAddr("172.24.16.9")) {
		t.Fatal("the first network should be let in")
	}
	// WSL restarts on another network; the next job comes from it, well within the hour.
	now = []Adapter{{Name: "vEthernet (WSL)", Prefixes: []netip.Prefix{netip.MustParsePrefix("192.168.208.1/20")}}}
	if !allow(netip.MustParseAddr("192.168.210.4")) {
		t.Fatal("the new network should be let in at its first job")
	}
	if allow(netip.MustParseAddr("172.24.16.9")) {
		t.Fatal("the old network should no longer be let in")
	}
	before := looks
	allow(netip.MustParseAddr("192.168.210.4"))
	if looks != before {
		t.Fatal("an address it knows should not make it look again")
	}
}

func TestStrangersDoNotMakeItLookAllTheTime(t *testing.T) {
	looks := 0
	allow := Allow(func() ([]Adapter, error) { looks++; return shopPC, nil }, time.Hour)
	for range 50 {
		allow(netip.MustParseAddr("10.92.139.37"))
	}
	if looks != 1 {
		t.Fatalf("it looked %d times for 50 connections within a second", looks)
	}
}
