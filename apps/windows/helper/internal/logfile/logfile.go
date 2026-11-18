// Package logfile is a log that keeps itself small on a PC that runs for years: past its size limit the file
// moves to <name>.1, replacing the one before, and a new one starts.
package logfile

import (
	"os"
	"path/filepath"
	"sync"
)

// File is an open log.
type File struct {
	mu   sync.Mutex
	path string
	max  int64
	f    *os.File
	size int64
}

// Open opens (or makes) the log at path, kept under max bytes, plus one older file.
func Open(path string, max int64) (*File, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return nil, err
	}
	l := &File{path: path, max: max}
	if err := l.open(); err != nil {
		return nil, err
	}
	return l, nil
}

func (l *File) open() error {
	f, err := os.OpenFile(l.path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		return err
	}
	info, err := f.Stat()
	if err != nil {
		f.Close()
		return err
	}
	l.f, l.size = f, info.Size()
	return nil
}

// Write appends p, moving the file aside first if p would take it past its limit.
func (l *File) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if l.size > 0 && l.size+int64(len(p)) > l.max {
		l.f.Close()
		// The older file goes first: Windows does not rename onto a file that exists.
		_ = os.Remove(l.path + ".1")
		_ = os.Rename(l.path, l.path+".1")
		if err := l.open(); err != nil {
			return 0, err
		}
	}
	n, err := l.f.Write(p)
	l.size += int64(n)
	return n, err
}

// Close closes the log.
func (l *File) Close() error {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.f.Close()
}
