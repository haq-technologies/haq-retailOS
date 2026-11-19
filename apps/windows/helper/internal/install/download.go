package install

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// download fetches url into path, and keeps it only if its SHA-256 is want; it says how far it has got.
func download(ctx context.Context, out io.Writer, url, want, path string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	resp, err := (&http.Client{Timeout: 2 * time.Hour}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("%s answered %s", url, resp.Status)
	}
	part := path + ".part"
	f, err := os.Create(part)
	if err != nil {
		return err
	}
	defer os.Remove(part)
	sum := sha256.New()
	progress := &progressWriter{out: out, total: resp.ContentLength}
	_, err = io.Copy(io.MultiWriter(f, sum, progress), resp.Body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	if got := hex.EncodeToString(sum.Sum(nil)); got != want {
		return fmt.Errorf("the download is not the one expected (SHA-256 %s)", got)
	}
	return os.Rename(part, path)
}

// progressWriter says each tenth of a download, so the window shows it is still going.
type progressWriter struct {
	out         io.Writer
	total, done int64
	lastTenth   int64
}

func (p *progressWriter) Write(b []byte) (int, error) {
	p.done += int64(len(b))
	if p.total > 0 {
		if tenth := p.done * 10 / p.total; tenth > p.lastTenth {
			p.lastTenth = tenth
			fmt.Fprintf(p.out, "  %d%%\n", tenth*10)
		}
	}
	return len(b), nil
}
