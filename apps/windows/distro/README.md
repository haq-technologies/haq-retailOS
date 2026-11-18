# The haq-server distribution

The Linux the shop server runs in on a Windows PC (D86). The Windows setup imports it into WSL2 as a distribution called haq-server, owned by the hidden HAQServer account. Nobody sees it or signs in to it.

It is Ubuntu 24.04's own file system with:
- **systemd**, which WSL starts (`wsl.conf`): it starts Docker, and the stack's restart policies bring the shop back after a power cut.
- **Docker and Compose** from Ubuntu's packages, as `install.sh` installs them on the mini-PC.
- **Ubuntu's security updates every day** (`20auto-upgrades`), as on the mini-PC.
- **This commit's shop server installer** in `/opt/haq-install`: `install.sh`, `haq`, the updater, the restore, the off-site copy, the Compose file and the release public key. The setup runs `/opt/haq-install/windows-install.sh`, which reads the token and the owner's PIN the setup left, deletes them, keeps the shop's profile with the stack (where `haq printer` changes it later), runs `install.sh --windows` and adds the owner. Nothing is cloned at the shop. Only the images are pulled, with the shop's read-only token (D89).
- **No Windows programs from inside** (`[interop] enabled=false`): Windows drives the server through `wsl.exe`, never the other way.

`/etc/haq-server-release` names the commit it was built from.

## Building it

CI builds and checks it on every pull request (the job "windows distro"). It keeps the one built on main or a release branch for 90 days, as the artifact `haq-server-distro`, for the release made from that commit to carry. By hand, with Docker on an x86-64 machine:

```
apps/windows/distro/build.sh
```

It writes `dist/haq-server.tar.gz` (about 170 MB) and its SHA-256, after checking that:
- WSL's settings, systemd, Docker and the installer are there, and nothing a container leaves behind is
- Docker, Compose and Python run inside it
- every installer script parses

GitHub's runners cannot run WSL2, so the import itself is tried on a real Windows PC (D90).

## After the install

The distribution is only the starting point. Once a shop is installed:
- Ubuntu keeps itself patched.
- The shop server updates with `haq update`, as on the mini-PC; the updater and scripts it uses live with the stack in `/opt/haq-retail-os`, not in `/opt/haq-install`.
- A new distribution matters only to new installs.
