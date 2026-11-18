# The HAQ Helper

The Windows side of HAQ Retail OS, in Go (D88): one small program with no runtime. It runs on a PC in two ways, both set up by the setup program from its settings in `C:\ProgramData\HAQ\helper.json`. Each logs to `C:\ProgramData\HAQ\logs`, keeping under 5 MB plus one older file.

| What | Runs as | On | Does |
|---|---|---|---|
| The HAQ Helper service (`haq-helper service`) | The system account, from Windows' start; Windows starts it again 5, 10 and 30 seconds after a failure | Every PC with a USB receipt printer, and the PC that runs the server | The print bridge for the PC's receipt printer; on the server's PC, the report of its disks and clock every 15 minutes |
| The keep-alive (`haq-helper keepalive`) | The hidden HAQServer account, from Windows' start, before anyone signs in (proved by the spike, [../spike/README.md](../spike/README.md)) | The PC that runs the server | Holds the haq-server distribution open and starts it again if it stops; passes the PC's report into the server; starts the off-site copy when an off-site drive is plugged in |

## The PC's report

The server inside WSL cannot read Windows' disks, so the service writes the report `deploy/host-health.sh` writes on the mini-PC, field for field, into `C:\ProgramData\HAQ\status\host.json`:
- **Disks:** each disk's health from Windows (Healthy passes; Warning or Unhealthy fails) and the life it has left (100 less Windows' wear counter). Flash drives and virtual disks are left out.
- **The clock:** set from a time source, or not, from `w32tm /query /status`.

The keep-alive passes it to `haq host-report` inside the server every 15 minutes. A report over an hour old is not passed on, and the log says the service may have stopped.

## The off-site drives

When a drive labelled HAQ-OFFSITE-A or HAQ-OFFSITE-B is plugged in, the keep-alive runs the server's own copy, `offsite.sh copy-windows <letter> <A or B>`, once for each time it is plugged in. A drive already plugged in when the PC starts is copied to as well, as udev does on the mini-PC.

## The print bridge

It makes a receipt printer on this PC look like a network printer on port 9100, so the shop server prints to it exactly as to one ([app/Printing/NetworkPrinter.php](../../shop-server/app/Printing/NetworkPrinter.php)). It has no print logic of its own.

- **Jobs:** a receipt or a drawer kick (it starts with `ESC @`) is read whole and sent to the printer as it came, one job at a time.
- **The status query:** a connection that starts with `DLE EOT` is the status query. It is answered by the printer itself, when the printer is reached two-way. While another job prints, it gets the printer's last answer, within the server's one second.
- **Unplugged or off:** it stops listening, so the server reads the printer as offline and the counter offers Print pending receipt. Once the printer is back, it listens again.
- **Who may print:** only the shop server. On a counter PC, that is the server's address. On the PC that runs the server, it is WSL's own network, which the server reaches the bridge from. WSL chooses that network each time it starts, so the bridge looks it up as connections come, at most once a second for an address it does not know.

It reaches the printer, best first:
1. the Windows USB printing device: two-way, so it answers paper out and cover open
2. a USB virtual COM port: also two-way
3. the printer's Windows print queue in RAW mode: one-way, so it answers the status query from the queue's state

A print queue on a USB port (USB001) is tied to its USB printing device, so a printer with a Windows driver is still reached two-way.

## Commands

```
haq-helper service [run|install|start|stop|remove|state]   the service; the setup installs and starts it
haq-helper keepalive                         keeps the hidden server running; its task runs it as HAQServer
haq-helper health [--write]                  the PC's disks and clock, as the server reads them
haq-helper drives                            the drives, and which are off-site drives
haq-helper printers [--json]                 the printers on this PC, and how each is reached
haq-helper test-print --printer "XP-80C"     the test receipt, with a barcode and a QR code for the scanner check
haq-helper bridge --printer "XP-80C" --allow 192.168.1.10
haq-helper version
```

`haq-helper service` run in a console, not by Windows, does the service's work there until Ctrl+C, logging to the console.

A printer is named as `printers` lists it: its Windows name, its port (USB001, COM3) or its target. `file:<path>` stands in for a printer anywhere, for development.

## Building and testing

From the repository root, with Docker (Go is never installed on the Mac):

```
docker run --rm -v "$PWD/apps/windows/helper:/src" -w /src golang:1.27 go test -race ./...
docker run --rm -v "$PWD/apps/windows/helper:/src" -w /src -e GOOS=windows golang:1.27 go build -o haq-helper.exe ./cmd/haq-helper
```

CI runs the tests with the race detector, vets the Windows code, checks for known vulnerabilities and builds the exe (windows helper). The keep-alive is tested against a stand-in for `wsl.exe`. On Windows itself (windows helper on Windows), CI:
- makes a print queue on a file port and checks that a RAW job reaches it byte for byte
- installs the service twice, starts it, sends a receipt through its bridge from the server's address and compares the bytes, then stops and removes it
- reads the runner's disks and clock as the server would

GitHub's runners cannot run WSL2, so the keep-alive itself is tried on a real Windows PC (D90). A real USB printer is first met at an install: the setup's test receipt confirms it (D60).
