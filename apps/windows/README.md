# The Windows setup

The Windows parts of HAQ Retail OS (D86 to D88). One setup program installs the whole shop on a Windows 10 (22H2) or 11 PC, with the shop server hidden in WSL2, or makes a PC a counter of a shop that already has a server. USB receipt printers work through the HAQ print bridge. The shop server itself is the same code as on the Ubuntu mini-PC: `apps/shop-server/`, with its own installer and updater inside the hidden distribution.

| Folder | What | State |
|---|---|---|
| [spike/](spike/README.md) | A throwaway test on real Windows: the server hidden in WSL2, started at power-on, reached through the PC's address, and printing to a stand-in bridge | Run on the product owner's PC: checks 1, 3 and 4 passed |
| [distro/](distro/README.md) | The haq-server distribution, built by CI from each commit | Built |
| [helper/](helper/README.md) | The HAQ Helper, in Go: it keeps the server running, is the print bridge, reports the PC's disk and clock, and starts the off-site copy | The print bridge and the printer list are built; the rest follows the spike |
| setup/ | The setup program, in Inno Setup | To be built |

The order and the checks are in PLAN.md, in the Windows setup checklist.
