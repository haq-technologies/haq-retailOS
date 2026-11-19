package install

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/haq-technologies/haq-retailOS/apps/windows/helper/internal/config"
)

// The runner: WSL belongs to a Windows user, so a distribution owned by HAQServer is reached only as
// HAQServer. The setup runs as an administrator; it writes each command to the runner's queue and starts a
// scheduled task that runs it as HAQServer, then follows its output, as the spike proved
// (apps/windows/spike). Only administrators can write the queue; HAQServer writes only the output.

// job is one command for the runner.
type job struct {
	Argv []string `json:"argv"`
}

// starter starts the runner's task, and says whether it is running and, if it is not, why.
type starter interface {
	Start(ctx context.Context) error
	State(ctx context.Context, since time.Time) (running bool, why string, err error)
}

// notStartedAfter is how long a task may take to start before the setup asks Windows why it has not.
var notStartedAfter = time.Minute

// RunnerDir is the runner's folder: its queue, and the output HAQServer writes.
func RunnerDir() string { return filepath.Join(config.Dir(), "runner") }

func queuePath(dir string) string  { return filepath.Join(dir, "queue.json") }
func outputPath(dir string) string { return filepath.Join(dir, "out", "output.log") }
func donePath(dir string) string   { return filepath.Join(dir, "out", "done") }

// asServer runs argv as HAQServer through the runner, copying its output to out as it comes, and returns
// its exit code.
func asServer(ctx context.Context, dir string, s starter, out io.Writer, argv ...string) (int, error) {
	raw, err := json.Marshal(job{Argv: argv})
	if err != nil {
		return -1, err
	}
	if err := os.MkdirAll(filepath.Join(dir, "out"), 0o755); err != nil {
		return -1, err
	}
	if err := os.Remove(donePath(dir)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return -1, err
	}
	if err := os.WriteFile(outputPath(dir), nil, 0o644); err != nil {
		return -1, err
	}
	if err := config.WriteFile(queuePath(dir), raw); err != nil {
		return -1, err
	}
	// The command may hold a PIN (`server add-user`), so it is kept only while it runs.
	defer os.Remove(queuePath(dir))
	began := time.Now()
	if err := s.Start(ctx); err != nil {
		return -1, fmt.Errorf("could not start the runner task: %w", err)
	}
	var offset int64
	follow := func() {
		f, err := os.Open(outputPath(dir))
		if err != nil {
			return
		}
		defer f.Close()
		if _, err := f.Seek(offset, io.SeekStart); err == nil {
			n, _ := io.Copy(out, f)
			offset += n
		}
	}
	lastAsked := began
	for {
		select {
		case <-ctx.Done():
			return -1, ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
		follow()
		if code, ok := doneCode(dir); ok {
			follow()
			return code, nil
		}
		// A task that never starts leaves no output and no end: say why within a minute.
		if time.Since(began) > notStartedAfter && time.Since(lastAsked) > notStartedAfter/6 {
			lastAsked = time.Now()
			running, why, err := s.State(ctx, began)
			if err == nil && !running {
				time.Sleep(time.Second)
				if code, ok := doneCode(dir); ok {
					follow()
					return code, nil
				}
				return -1, fmt.Errorf("the runner task, as %s, is not running and did not finish: %s", account, why)
			}
		}
	}
}

func doneCode(dir string) (int, bool) {
	raw, err := os.ReadFile(donePath(dir))
	if err != nil {
		return 0, false
	}
	code, err := strconv.Atoi(strings.TrimSpace(string(raw)))
	if err != nil {
		return -1, true
	}
	return code, true
}

// RunQueued is the runner task's side: it runs the queued command, its output and errors into the output
// file, and then writes its exit code.
func RunQueued(dir string) int {
	code := runQueued(dir)
	_ = config.WriteFile(donePath(dir), []byte(strconv.Itoa(code)))
	return code
}

func runQueued(dir string) int {
	out, err := os.OpenFile(outputPath(dir), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		return 125
	}
	defer out.Close()
	raw, err := os.ReadFile(queuePath(dir))
	if err != nil {
		fmt.Fprintln(out, "The runner has no command:", err)
		return 125
	}
	var j job
	if err := json.Unmarshal(raw, &j); err != nil || len(j.Argv) == 0 {
		fmt.Fprintln(out, "The runner's command is not right:", err)
		return 125
	}
	cmd := exec.Command(j.Argv[0], j.Argv[1:]...)
	cmd.Stdout, cmd.Stderr = out, out
	// WSL writes its own messages as UTF-8 rather than UTF-16.
	cmd.Env = append(os.Environ(), "WSL_UTF8=1")
	if err := cmd.Run(); err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) {
			return exit.ExitCode()
		}
		fmt.Fprintln(out, "Could not run it:", err)
		return 126
	}
	return 0
}
