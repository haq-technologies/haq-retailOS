# The HAQ Helper

The Windows side of HAQ Retail OS, in Go (D88): one small program with no runtime. Today it is the print bridge (D87) and what the setup needs to choose a printer. Keeping the hidden server running, the PC's disk and clock report, and the off-site drives follow, once the spike has shown how the server runs ([../spike/README.md](../spike/README.md)).

## The print bridge

It makes a receipt printer on this PC look like a network printer on port 9100, so the shop server prints to it exactly as to one ([app/Printing/NetworkPrinter.php](../../shop-server/app/Printing/NetworkPrinter.php)). It has no print logic of its own.

- **Jobs:** a receipt or a drawer kick (it starts with `ESC @`) is read whole and sent to the printer as it came, one job at a time.
- **The status query:** a connection that starts with `DLE EOT` is the status query. It is answered by the printer itself, when the printer is reached two-way. While another job prints, it gets the printer's last answer, within the server's one second.
- **Unplugged or off:** it stops listening, so the server reads the printer as offline and the counter offers Print pending receipt. Once the printer is back, it listens again.
- **Who may print:** `--allow` names the shop server's address; the setup's firewall rule allows the same.

It reaches the printer, best first:
1. the Windows USB printing device: two-way, so it answers paper out and cover open
2. a USB virtual COM port: also two-way
3. the printer's Windows print queue in RAW mode: one-way, so it answers the status query from the queue's state

A print queue on a USB port (USB001) is tied to its USB printing device, so a printer with a Windows driver is still reached two-way.

## Commands

```
haq-helper printers [--json]                 the printers on this PC, and how each is reached
haq-helper test-print --printer "XP-80C"     the test receipt, with a barcode and a QR code for the scanner check
haq-helper bridge --printer "XP-80C" --allow 192.168.1.10
haq-helper version
```

A printer is named as `printers` lists it: its Windows name, its port (USB001, COM3) or its target. `file:<path>` stands in for a printer anywhere, for development.

## Building and testing

From the repository root, with Docker (Go is never installed on the Mac):

```
docker run --rm -v "$PWD/apps/windows/helper:/src" -w /src golang:1.27 go test -race ./...
docker run --rm -v "$PWD/apps/windows/helper:/src" -w /src -e GOOS=windows golang:1.27 go build -o haq-helper.exe ./cmd/haq-helper
```

CI runs the tests with the race detector, vets the Windows code, checks for known vulnerabilities and builds the exe (windows helper). On Windows itself, it makes a print queue on a file port and checks that a RAW job reaches it byte for byte (windows helper on Windows). A real USB printer is first met at an install: the setup's test receipt confirms it (D60).
