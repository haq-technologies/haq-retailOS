# The setup program

`HAQ-Retail-OS-Setup-<version>.exe`, made with Inno Setup (D88). One file installs the whole shop on a 64-bit Windows 10 or 11 PC, or makes a PC a counter of a shop whose server is another PC. It asks the questions; the HAQ Helper's install engine (`haq-helper setup`, [../helper/README.md](../helper/README.md)) does the install, in a window that numbers each step.

## Which PCs

The setup checks the PC itself, not a version number, and says in plain words what to do about anything that stops it (D92). Nobody needs to look up the PC's Windows version first.

| The PC | A counter | The shop's server |
|---|---|---|
| 64-bit Windows 11, any edition | Yes | Yes |
| 64-bit Windows 10 20H2 to 22H2, updated since November 2022, any edition (Home, Pro, Enterprise, Education, LTSC 2021) | Yes | Yes |
| 64-bit Windows 10 older than that, or not updated since | Yes | Not yet: it asks for Windows Update (free) first, because Microsoft's current WSL needs it |
| Virtualisation turned off in the firmware (BIOS or UEFI) | Yes | Not yet: it says how to turn it on |
| An ARM processor (some Windows 11 laptops) | Yes, though not yet tried on one | No: the server's Linux is for Intel and AMD processors |
| 32-bit Windows | No | No |
| Windows in S mode | No: Windows runs no setup program in S mode; leaving it is free, in the Microsoft Store | No, for the same reason |

The server is tested with 8 GB of memory. On a PC with less, the setup says so and asks whether to go on, until the server's memory use is measured and the least it needs is set (D92). It also needs 30 GB free.

## What it asks

| Page | This PC runs the shop | This PC is a counter |
|---|---|---|
| What is this PC? | It checks the PC can run the shop. If Windows' Linux (WSL) is off, it turns it on, with Microsoft's WSL installer (about 370 MB); you restart and run the setup again | It checks the PC |
| This PC's address | The PC's fixed address, which every device reaches it at | The same, for its print bridge |
| The download token | The GitHub user name and the read-only token, asked this once; the server keeps it for every update (D89) | - |
| The shop | The name, and the receipt's heading, address, phone and footer | - |
| The counters | How many, and which have a cash drawer | - |
| The owner | The owner's name and 6-digit PIN, typed twice. The staff are added after the setup (below) | - |
| The shop's server | - | The server's address, which counter this is (T02, T03 ...), and the one-time code from `haq-helper server enrol T02` on the server's PC |
| The receipt printer | A printer plugged into this PC (it prints a test receipt, and asks whether it came out), a network printer's address, or none yet. The list is read when the setup starts, so plug the printer in and switch it on first; it can be set or changed later with `haq-helper printer` (D139) | The same |

Then it installs: 15 to 40 minutes for the shop, most of it downloading, and a minute for a counter. At the end it opens the page that registers the PC as a counter, with the code filled in. A counter's last page also gives the line to run on the server's PC for its printer.

## After it: the staff and the back office

The setup puts its folder on the system PATH, so the shop server's own commands run in a new Terminal (Admin) on the server's PC as `haq-helper server ...`. Until the owner's People screen comes (spec section 3), HAQ support adds each person there, with their role (cashier, manager, storekeeper or owner); the helper asks the PIN, hidden and twice, as `sudo haq add-user` does on Ubuntu:

```
haq-helper server add-user "Amina" cashier
haq-helper server users
haq-helper server pin "Amina"
haq-helper server deactivate "Amina"
```

On a shop with one PC, the cashier and the owner share the counter, each with their own name tile. The counter's name tiles have **Open the back office**, for the owner, a manager or the storekeeper; it keeps a sale waiting on the counter. **Open the counter** goes back, and after the idle lock's minutes without use the back office on a counter goes back to the counter's name tiles by itself (D91).

## Running it again

On a PC that has HAQ Retail OS, a newer setup asks nothing. It stops the HAQ Helper and the keep-alive, puts in its files, and starts them again. The shop and its sales stay as they were; the shop server itself updates with `haq-helper server update`.

An older release's setup stops before it changes anything, and says which release the PC has (D137): run that release's setup or a newer one. It compares releases only (YEAR.MONTH.N), so a build from main can always be tried. HAQ support goes back to an older release on purpose with `/ALLOWDOWNGRADE`.

**Uninstall** (Settings, Apps) removes the Windows parts: the HAQ Helper, its tasks, the port forwarding, the firewall rules, the start-up shortcut, the trusted certificate and its folder on the PATH. The shop and its sales stay in `C:\ProgramData\HAQ`, and running the setup again brings them back. Only HAQ support deletes them for good: `haq-helper setup remove --delete-shop-and-sales`.

## Building it

CI builds it on every pull request (the job "windows setup"), with this commit's HAQ Helper and haq-server distribution, and keeps the one built on main or a release branch for 90 days. Inno Setup 6.7.4 is downloaded from its releases and checked against its SHA-256. By hand, on Windows:

```
ISCC /DAppVersion=2026.12.1 /DNumericVersion=2026.12.1.0 /DTag=2026.12.1 /DHelper=<haq-helper.exe> /DDistro=<haq-server.tar.gz> haq-setup.iss
```

A build from main installs the `main` images. A release's build installs that release's images, by its version (D70).

CI also runs it silently as a counter against a stand-in server, runs it a second time as an update, then uninstalls it, with [test-setup.ps1](test-setup.ps1); windows-release.yml runs the same test on each release's own setup before keeping it (D137). CI also checks that an older release's setup stops on a PC that has a newer one:

```
HAQ-Retail-OS-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /MODE=counter /ANSWERS=<file>
```

The answers file is the engine's (`internal/install/answers.go`). The setup deletes it once it has a copy, since a server's answers hold the token.

Unsigned, Windows SmartScreen asks "Run anyway?". That is acceptable while only HAQ's team installs; a code-signing certificate comes before shops download it themselves (PLAN.md, Open items).
