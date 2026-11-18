#!/bin/bash
# Builds the haq-server distribution (D86) from this checkout and checks it: Ubuntu 24.04's file system with
# systemd, Docker and this commit's shop server installer, as the gzipped tar the Windows setup imports into
# WSL2. CI builds it on every pull request and keeps the one from main, which a release then carries.
#
#   apps/windows/distro/build.sh [--out <folder>]     (default apps/windows/distro/dist)
#
# It writes haq-server.tar.gz and haq-server.tar.gz.sha256. It needs Docker with buildx, on x86-64, as a
# shop's Windows PC is.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
out="$here/dist"
while [ $# -gt 0 ]; do
    case "$1" in
        --out) out="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
mkdir -p "$out"
out="$(cd "$out" && pwd)"
tar="$out/haq-server.tar"
commit="$(git -C "$root" rev-parse HEAD)"

echo "Building the distribution from commit $commit..."
rm -f "$tar" "$tar.gz" "$tar.gz.sha256"
docker buildx build --platform linux/amd64 --file "$here/Dockerfile" --build-arg "COMMIT=$commit" \
    --output "type=tar,dest=$tar" "$root"

fail() { echo "The distribution is not right: $*" >&2; exit 1; }

# What WSL and the setup need is there; what a container leaves behind is not.
listing="$(tar -tf "$tar" | sed 's#^\./##')"
for path in etc/wsl.conf etc/haq-server-release etc/apt/apt.conf.d/20auto-upgrades usr/lib/systemd/systemd \
    usr/bin/dockerd usr/bin/docker etc/systemd/system/multi-user.target.wants/docker.service \
    opt/haq-install/install.sh opt/haq-install/windows-install.sh opt/haq-install/haq opt/haq-install/update.sh opt/haq-install/offsite.sh \
    opt/haq-install/compose.yaml opt/haq-install/release-public.pem; do
    grep -qx -- "$path" <<<"$listing" || fail "$path is missing"
done
for path in .dockerenv opt/haq-install/staging.sh; do
    if grep -qx -- "$path" <<<"$listing"; then fail "$path should not be there"; fi
done
if grep -q '^opt/haq-install/secrets' <<<"$listing"; then fail "it holds a secrets folder"; fi

# Inside it, as a plain container: the tools the installer calls, and every script parses.
image="haq-server-check:${commit:0:12}"
docker import --platform linux/amd64 "$tar" "$image" >/dev/null
trap 'docker image rm -f "$image" >/dev/null 2>&1 || true' EXIT
docker run --rm --network none "$image" /bin/bash -euo pipefail -c '
    [ "$(readlink -f /sbin/init)" = /usr/lib/systemd/systemd ]
    grep -qx "systemd=true" /etc/wsl.conf
    grep -qx "commit='"$commit"'" /etc/haq-server-release
    docker --version
    docker compose version
    python3 --version
    for tool in curl rsync ip ps mount base64 systemctl; do command -v "$tool" >/dev/null || { echo "no $tool" >&2; exit 1; }; done
    for script in /opt/haq-install/*.sh /opt/haq-install/haq; do
        [ -x "$script" ] || { echo "$script is not executable" >&2; exit 1; }
        bash -n "$script"
    done
    . /etc/os-release && echo "$PRETTY_NAME"
' || fail "a check inside it failed"

gzip -n "$tar"
# sha256sum on Linux, shasum on a Mac; both write "<hash>  <file>".
if command -v sha256sum >/dev/null; then sum=(sha256sum); else sum=(shasum -a 256); fi
(cd "$out" && "${sum[@]}" haq-server.tar.gz > haq-server.tar.gz.sha256)
echo "Built $out/haq-server.tar.gz ($(du -m "$out/haq-server.tar.gz" | cut -f1) MB): $(cut -d' ' -f1 "$out/haq-server.tar.gz.sha256")"
