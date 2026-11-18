#!/bin/bash
# The shop server's first install on a Windows PC (D86). The HAQ Helper's setup runs it as root inside the
# haq-server distribution, from the files it left for this one install:
#
#   /opt/haq-install/windows-install.sh <ip> <tag> <folder>
#
# <folder> is the PC's C:\ProgramData\HAQ\install as seen from inside, holding profile.json, ghcr_user,
# ghcr_token, owner_name and owner_pin. The token and the PIN are deleted as soon as they are read; install.sh
# keeps its own copy of the token, root only, for the updater. Run again after a first install that stopped,
# it keeps the profile already in place and adds the owner only if the shop has none.
set -euo pipefail

ip="${1:?Give the fixed address of the PC}"
tag="${2:?Give the release, such as 2026.12.1}"
from="${3:?Give the folder the setup left the files in}"
dir=/opt/haq-retail-os
haq=/usr/local/bin/haq

for f in profile.json ghcr_user ghcr_token owner_name owner_pin; do
    [ -f "$from/$f" ] || { echo "The setup left no $f in $from." >&2; exit 2; }
done
GHCR_USER="$(cat "$from/ghcr_user")"
GHCR_TOKEN="$(cat "$from/ghcr_token")"
owner_name="$(cat "$from/owner_name")"
owner_pin="$(cat "$from/owner_pin")"
rm -f "$from/ghcr_user" "$from/ghcr_token" "$from/owner_pin"
export GHCR_USER GHCR_TOKEN

# The profile lives with the stack, root only, where `haq printer` changes it as each counter PC is installed.
install -d -m 700 "$dir"
if [ ! -f "$dir/shop.profile.json" ]; then
    install -m 600 "$from/profile.json" "$dir/shop.profile.json"
fi
rm -f "$from/profile.json" "$from/owner_name"

/opt/haq-install/install.sh --windows --ip "$ip" --tag "$tag" --profile "$dir/shop.profile.json" </dev/null
unset GHCR_TOKEN

if "$haq" users | grep -q '| Owner '; then
    echo "The shop has its owner already."
else
    "$haq" artisan shop:add-user "$owner_name" --role=owner --pin="$owner_pin"
fi
echo "Installed release $tag."
