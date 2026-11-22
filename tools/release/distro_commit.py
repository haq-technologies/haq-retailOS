"""Prints the commit a haq-server distribution was built from (D137).

build.sh writes it into the distribution as /etc/haq-server-release ("commit=<40 hex>"). make-manifest.sh and
check-windows.sh compare it with the manifest's commit, so a release never signs a Windows setup built from
another commit than its images (D81). It reads the gzipped tar as a stream and stops at that one file.

    python3 -I tools/release/distro_commit.py haq-server.tar.gz
"""

import re
import sys
import tarfile

WANT = "etc/haq-server-release"


def commit_of(path):
    with tarfile.open(path, "r|gz") as tar:
        for member in tar:
            name = member.name
            while name.startswith("./"):
                name = name[2:]
            if name != WANT or not member.isfile():
                continue
            text = tar.extractfile(member).read().decode("utf-8", "replace")
            for line in text.splitlines():
                found = re.fullmatch(r"commit=([0-9a-f]{40})", line.strip())
                if found:
                    return found.group(1)
            sys.exit(f"{path}: /{WANT} names no commit.")
    sys.exit(f"{path}: no /{WANT}, so it is not a haq-server distribution build.sh made.")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("Give the distribution: distro_commit.py haq-server.tar.gz")
    try:
        print(commit_of(sys.argv[1]))
    except (OSError, tarfile.TarError, EOFError) as problem:
        sys.exit(f"{sys.argv[1]}: not a gzipped tar it can read ({problem}).")
