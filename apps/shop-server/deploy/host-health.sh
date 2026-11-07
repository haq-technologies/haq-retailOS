#!/bin/sh
# The shop server's own report for the health page (spec section 4; check E6) [F24, D45]: each SSD's health
# and the life it has left, from smartctl, and whether the clock is set from an internet time source. The
# containers cannot read the disks' SMART data, so the host writes this every 15 minutes, through the
# haq-host-health timer that deploy/install.sh installs, into the backup disk's status folder, where the
# scheduler reads it.
#
#   deploy/host-health.sh /mnt/backup/status
#
# Ubuntu Server ships python3; smartctl comes from smartmontools, which the installer adds. Without it the
# report says so and the health page shows the disks as not reporting.
set -eu

status="${1:?Give the backup disk status folder}"
[ -d "$status" ] || { echo "There is no folder $status." >&2; exit 2; }

synced=$(timedatectl show -p NTPSynchronized --value 2>/dev/null || true)

STATUS="$status" SYNCED="$synced" python3 -I - <<'PY'
import json, os, shutil, subprocess, time

# The normalised value of the first wear attribute an ATA SSD reports: SSD_Life_Left,
# Percent_Lifetime_Remain, Media_Wearout_Indicator, Wear_Leveling_Count, then Available_Reservd_Space.
WEAR = [231, 202, 233, 177, 170]


def smart(device):
    # smartctl's exit status is a bit mask of warnings, so its output is read whatever the status.
    out = subprocess.run(["smartctl", "-j", "-a", device], capture_output=True, text=True).stdout
    data = json.loads(out or "{}")
    passed = data.get("smart_status", {}).get("passed")
    life = None
    nvme = data.get("nvme_smart_health_information_log", {})
    if isinstance(nvme.get("percentage_used"), int):
        life = max(0, 100 - nvme["percentage_used"])
    else:
        table = {a.get("id"): a for a in data.get("ata_smart_attributes", {}).get("table", [])}
        for wear in WEAR:
            if wear in table and isinstance(table[wear].get("value"), int):
                life = min(100, table[wear]["value"])
                break
    return {
        "device": data.get("device", {}).get("name", device),
        "model": data.get("model_name"),
        "passed": passed if isinstance(passed, bool) else None,
        "life_left": life,
    }


disks = []
have = shutil.which("smartctl") is not None
if have:
    scan = subprocess.run(["smartctl", "--scan"], capture_output=True, text=True).stdout
    for line in scan.splitlines():
        device = line.split(" ", 1)[0]
        if device.startswith("/dev/"):
            try:
                disks.append(smart(device))
            except (ValueError, OSError):
                disks.append({"device": device, "model": None, "passed": None, "life_left": None})

synced = os.environ.get("SYNCED", "")
report = {
    "written_at": int(time.time()),
    "smartctl": have,
    "disks": disks,
    "time_synced": True if synced == "yes" else False if synced == "no" else None,
}
path = os.path.join(os.environ["STATUS"], "host.json")
with open(path + ".tmp", "w") as f:
    json.dump(report, f, indent=2)
os.chmod(path + ".tmp", 0o644)
os.replace(path + ".tmp", path)
PY
