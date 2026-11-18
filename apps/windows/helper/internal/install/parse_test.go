package install

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha1"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/pem"
	"math/big"
	"strings"
	"testing"
	"time"
)

func testCA(t *testing.T) []byte {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: caSubject},
		NotBefore: time.Now(), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
}

func TestTheCertificateIsFoundAmongDockersWarnings(t *testing.T) {
	ca := testCA(t)
	out := append([]byte("time=\"2026-11-18T10:00:00Z\" level=warning msg=\"The \\\"X\\\" variable is not set.\"\n"), ca...)
	got, err := certificate(out)
	if err != nil || string(got) != string(ca) {
		t.Fatalf("got %q, %v", got, err)
	}
	if _, err := certificate([]byte("Error: no such container")); err == nil {
		t.Fatal("no certificate is an error")
	}
}

func TestTheThumbprintIsWindowsOwn(t *testing.T) {
	ca := testCA(t)
	block, _ := pem.Decode(ca)
	sum := sha1.Sum(block.Bytes)
	got, err := thumbprint(ca)
	if err != nil {
		t.Fatal(err)
	}
	if strings.ReplaceAll(got, " ", "") != strings.ToUpper(hex.EncodeToString(sum[:])) || len(strings.Fields(got)) != 10 {
		t.Fatalf("got %q", got)
	}
	// As the setup page shows it, with a new line; or typed in lower case with colons.
	if !sameThumbprint(got, got+"\n") || !sameThumbprint(got, strings.ToLower(strings.ReplaceAll(got, " ", ":"))) {
		t.Fatal("the same thumbprint, differently written, should match")
	}
	if sameThumbprint("", "") || sameThumbprint(got, "AB12") {
		t.Fatal("an empty or a different thumbprint should not match")
	}
}

func TestTheEnrolCodeIsTheLastLine(t *testing.T) {
	out := "\n   INFO  On T01 (Counter 1), open /enrol in the browser and type the code below.\n\nIt works once, for 30 minutes.\n4827-1093\n"
	if code, err := enrolCodeFrom(out); err != nil || code != "4827-1093" {
		t.Fatalf("got %q, %v", code, err)
	}
	if _, err := enrolCodeFrom("There is no device T09 in this shop."); err == nil {
		t.Fatal("an error message is not a code")
	}
}

func TestTheDistributionsAreReadOneALine(t *testing.T) {
	got := distributions("Ubuntu\r\nhaq-server\r\n\r\n")
	if strings.Join(got, ",") != "Ubuntu,haq-server" {
		t.Fatalf("got %q", got)
	}
}
