# The setup program

`HAQ-Retail-OS-Setup-<version>.exe`, made with Inno Setup (D88). One file installs the whole shop on a Windows 10 (22H2) or 11 PC, or makes a PC a counter of a shop whose server is another PC. It asks the questions; the HAQ Helper's install engine (`haq-helper setup`, [../helper/README.md](../helper/README.md)) does the install, in a window that numbers each step.

## What it asks

| Page | This PC runs the shop | This PC is a counter |
|---|---|---|
| What is this PC? | It checks the PC can run the shop. If Windows' Linux (WSL) is off, it turns it on; you restart and run the setup again | It checks the PC |
| This PC's address | The PC's fixed address, which every device reaches it at | The same, for its print bridge |
| The download token | The GitHub user name and the read-only token, asked this once; the server keeps it for every update (D89) | - |
| The shop | The name, and the receipt's heading, address, phone and footer | - |
| The counters | How many, and which have a cash drawer | - |
| The owner | The owner's name and 6-digit PIN, typed twice | - |
| The shop's server | - | The server's address, which counter this is (T02, T03 ...), and the one-time code from `haq-helper server enrol T02` on the server's PC |
| The receipt printer | A printer plugged into this PC (it prints a test receipt, and asks whether it came out), a network printer's address, or none yet | The same |

Then it installs: 15 to 40 minutes for the shop, most of it downloading, and a minute for a counter. At the end it opens the page that registers the PC as a counter, with the code filled in. A counter's last page also gives the line to run on the server's PC for its printer.

## Running it again

On a PC that has HAQ Retail OS, a newer setup asks nothing. It stops the HAQ Helper and the keep-alive, puts in its files, and starts them again. The shop and its sales stay as they were; the shop server itself updates with `haq-helper server update`.

**Uninstall** (Settings, Apps) removes the Windows parts: the HAQ Helper, its tasks, the port forwarding, the firewall rules, the start-up shortcut and the trusted certificate. The shop and its sales stay in `C:\ProgramData\HAQ`, and running the setup again brings them back. Only HAQ support deletes them for good: `haq-helper setup remove --delete-shop-and-sales`.

## Building it

CI builds it on every pull request (the job "windows setup"), with this commit's HAQ Helper and haq-server distribution, and keeps the one built on main or a release branch for 90 days. Inno Setup 6.7.4 is downloaded from its releases and checked against its SHA-256. By hand, on Windows:

```
ISCC /DAppVersion=2026.12.1 /DNumericVersion=2026.12.1.0 /DTag=2026.12.1 /DHelper=<haq-helper.exe> /DDistro=<haq-server.tar.gz> haq-setup.iss
```

A build from main installs the `main` images. A release's build installs that release's images, by its version (D70).

CI also runs it silently as a counter against a stand-in server, runs it a second time as an update, then uninstalls it:

```
HAQ-Retail-OS-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /MODE=counter /ANSWERS=<file>
```

The answers file is the engine's (`internal/install/answers.go`). The setup deletes it once it has a copy, since a server's answers hold the token.

Unsigned, Windows SmartScreen asks "Run anyway?". That is acceptable while only HAQ's team installs; a code-signing certificate comes before shops download it themselves (PLAN.md, Open items).
