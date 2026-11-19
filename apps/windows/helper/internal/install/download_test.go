package install

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

func serve(t *testing.T, body []byte) string {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Length", strconv.Itoa(len(body))) // as GitHub's downloads say
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)
	return srv.URL
}

func TestADownloadIsKeptOnlyWhenItsHashIsTheOneExpected(t *testing.T) {
	body := bytes.Repeat([]byte("WSL"), 1000)
	sum := sha256.Sum256(body)
	path := filepath.Join(t.TempDir(), "downloads", "wsl.msi")
	var said bytes.Buffer
	if err := download(context.Background(), &said, serve(t, body), hex.EncodeToString(sum[:]), path); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(path); !bytes.Equal(got, body) {
		t.Fatal("the file is not what was served")
	}
	if !strings.Contains(said.String(), "100%") {
		t.Fatalf("it did not say how far it got: %q", said.String())
	}
}

func TestADownloadWithAnotherHashIsThrownAway(t *testing.T) {
	path := filepath.Join(t.TempDir(), "wsl.msi")
	err := download(context.Background(), &bytes.Buffer{}, serve(t, []byte("not Microsoft's")), strings.Repeat("0", 64), path)
	if err == nil || !strings.Contains(err.Error(), "not the one expected") {
		t.Fatalf("got %v", err)
	}
	for _, p := range []string{path, path + ".part"} {
		if _, err := os.Stat(p); err == nil {
			t.Fatalf("%s was kept", p)
		}
	}
}
