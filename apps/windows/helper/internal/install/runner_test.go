//go:build !windows

package install

import (
	"bytes"
	"context"
	"strings"
	"testing"
	"time"
)

// taskNow stands in for the runner task: it runs the queue at once, as the task would as HAQServer.
type taskNow struct{ dir string }

func (t taskNow) Start(context.Context) error {
	go RunQueued(t.dir)
	return nil
}

func (taskNow) State(context.Context, time.Time) (bool, string, error) { return true, "", nil }

// taskRefused stands in for a task Windows will not start, as the spike's first run met.
type taskRefused struct{}

func (taskRefused) Start(context.Context) error { return nil }

func (taskRefused) State(context.Context, time.Time) (bool, string, error) {
	return false, "Windows refused HAQServer's sign-in: it may not sign in as a batch job", nil
}

func TestACommandRunsThroughTheQueueWithItsOutputAndCode(t *testing.T) {
	dir := t.TempDir()
	var out bytes.Buffer
	code, err := asServer(context.Background(), dir, taskNow{dir}, &out, "/bin/sh", "-c", `echo "Import in progress"; echo "WSL_UTF8=$WSL_UTF8"; exit 3`)
	if err != nil {
		t.Fatal(err)
	}
	if code != 3 {
		t.Fatalf("exit code %d, want 3", code)
	}
	if got := out.String(); got != "Import in progress\nWSL_UTF8=1\n" {
		t.Fatalf("output %q", got)
	}
}

func TestTheNextCommandStartsClean(t *testing.T) {
	dir := t.TempDir()
	var first, second bytes.Buffer
	if _, err := asServer(context.Background(), dir, taskNow{dir}, &first, "/bin/echo", "first"); err != nil {
		t.Fatal(err)
	}
	code, err := asServer(context.Background(), dir, taskNow{dir}, &second, "/bin/echo", "second")
	if err != nil || code != 0 || second.String() != "second\n" {
		t.Fatalf("code %d, %v, output %q", code, err, second.String())
	}
}

func TestAProgramThatIsNotThereIsAnExitCodeNotAHang(t *testing.T) {
	dir := t.TempDir()
	var out bytes.Buffer
	code, err := asServer(context.Background(), dir, taskNow{dir}, &out, "/no/such/program")
	if err != nil || code != 126 || !strings.Contains(out.String(), "Could not run it") {
		t.Fatalf("code %d, %v, output %q", code, err, out.String())
	}
}

func TestATaskThatNeverStartsSaysWhyWithinAMinute(t *testing.T) {
	notStartedAfter = 200 * time.Millisecond
	t.Cleanup(func() { notStartedAfter = time.Minute })
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_, err := asServer(ctx, t.TempDir(), taskRefused{}, &bytes.Buffer{}, "/bin/true")
	if err == nil || !strings.Contains(err.Error(), "batch job") {
		t.Fatalf("got %v, want Windows' reason", err)
	}
}
