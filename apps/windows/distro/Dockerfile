# The haq-server distribution (D86): Ubuntu 24.04 with systemd and Docker, and the shop server's installer
# from this commit. Its file system is exported as a tar, which the Windows setup imports into WSL2 as the
# hidden haq-server distribution; it is never run as a container. build.sh builds and checks it, from the
# repository root as the build context.
FROM ubuntu:noble-20261110.1

# Security updates as built; systemd, which WSL starts (wsl.conf); Docker from Ubuntu's own packages, as on
# the mini-PC (install.sh); and what the installer, the updater, the restore and the off-site copy call.
RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get -y upgrade \
    && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus ca-certificates curl python3 iproute2 procps rsync util-linux \
        docker.io docker-compose-v2 unattended-upgrades \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY apps/windows/distro/wsl.conf /etc/wsl.conf
COPY apps/windows/distro/20auto-upgrades /etc/apt/apt.conf.d/20auto-upgrades

# The release's installer, updater and Compose file, as install.sh expects them beside it. The setup runs
# /opt/haq-install/install.sh --windows; nothing is cloned or downloaded for it at the shop.
COPY apps/shop-server/deploy/ /opt/haq-install/
COPY apps/windows/distro/windows-install.sh /opt/haq-install/windows-install.sh
ARG COMMIT=unknown
RUN chmod 0755 /opt/haq-install/*.sh /opt/haq-install/haq \
    && printf 'HAQ Retail OS server distribution\ncommit=%s\n' "$COMMIT" > /etc/haq-server-release
