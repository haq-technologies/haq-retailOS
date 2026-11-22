package install

import (
	"context"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"
)

// runWatch runs watchForward on a PC that has its address, for one check per answer, and returns how often
// it repaired the forwarding and what it logged.
func runWatch(t *testing.T, answers []bool) (int, []string) {
	t.Helper()
	var mu sync.Mutex
	var logs []string
	repairs, check := 0, 0
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		watchForward(ctx, func(f string, a ...any) { mu.Lock(); logs = append(logs, fmt.Sprintf(f, a...)); mu.Unlock() }, time.Millisecond,
			func() bool { return true },
			func() bool {
				mu.Lock()
				defer mu.Unlock()
				if check >= len(answers) {
					cancel()
					return true
				}
				check++
				return answers[check-1]
			},
			func() error { mu.Lock(); repairs++; mu.Unlock(); return nil })
	}()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		cancel()
		t.Fatal("the watch did not stop")
	}
	mu.Lock()
	defer mu.Unlock()
	return repairs, logs
}

func TestTheForwardingIsMadeAgainWhenNothingAnswers(t *testing.T) {
	repairs, logs := runWatch(t, []bool{false, false, true, true})
	if repairs != 2 {
		t.Errorf("it repaired %d times for two checks that found nothing, want 2", repairs)
	}
	if len(logs) != 2 || !strings.Contains(logs[0], "made again") || !strings.Contains(logs[1], "answers") {
		t.Errorf("it logged %q, want one line when it broke and one when it answered again", logs)
	}
}

func TestAForwardingThatAnswersIsLeftAlone(t *testing.T) {
	repairs, logs := runWatch(t, []bool{true, true, true})
	if repairs != 0 || len(logs) != 0 {
		t.Errorf("it repaired %d times and logged %q for a forwarding that answers", repairs, logs)
	}
}

func TestNothingIsRepairedBeforeThePCHasItsAddress(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancel()
	repairs := 0
	watchForward(ctx, func(string, ...any) {}, time.Millisecond,
		func() bool { return false },
		func() bool { t.Error("it checked the address before the PC had it"); return false },
		func() error { repairs++; return nil })
	if repairs != 0 {
		t.Errorf("it repaired %d times before the PC had its address", repairs)
	}
}
