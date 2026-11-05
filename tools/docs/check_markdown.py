"""Check every Markdown file in the repository: tables keep the same number of columns as their header,
tables start after a blank line or a heading, and relative links point at files that exist.

Usage (from the repository root): python3 -I tools/docs/check_markdown.py
Exits with status 1 when it finds a problem, so CI fails.
"""
import os
import re
import sys

SKIP_DIRS = {".git", "node_modules", "vendor"}
SEPARATOR = re.compile(r"^\s*\|(\s*:?-{3,}:?\s*\|)+\s*$")
LINK = re.compile(r"\]\(([^)\s]+)\)")


def columns(line):
    return re.sub(r"\\\|", "", line.strip()).count("|") - 1


def check(path):
    problems, lines = [], open(path, encoding="utf-8").read().split("\n")
    in_code, i = False, 0
    while i < len(lines):
        line = lines[i]
        if line.startswith("```"):
            in_code = not in_code
        elif not in_code:
            for target in LINK.findall(line):
                if "://" in target or target.startswith(("#", "mailto:")):
                    continue
                resolved = os.path.normpath(os.path.join(os.path.dirname(path), target.split("#")[0]))
                if not os.path.exists(resolved):
                    problems.append(f"{path}:{i + 1}: broken link {target}")
            if line.strip().startswith("|") and i + 1 < len(lines) and SEPARATOR.match(lines[i + 1]):
                want = columns(line)
                if i > 0 and lines[i - 1].strip() and not lines[i - 1].startswith("#"):
                    problems.append(f"{path}:{i + 1}: table must follow a blank line or a heading")
                j = i + 1
                while j < len(lines) and lines[j].strip().startswith("|"):
                    if columns(lines[j]) != want:
                        problems.append(f"{path}:{j + 1}: {columns(lines[j])} columns, header has {want}")
                    j += 1
                i = j
                continue
        i += 1
    return problems


def main():
    files, problems = [], []
    for root, dirs, names in os.walk("."):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        files += [os.path.join(root, n) for n in names if n.endswith(".md")]
    for path in sorted(files):
        problems += check(path)
    for p in problems:
        print(p)
    print(f"{len(files)} Markdown files checked, {len(problems)} problems")
    sys.exit(1 if problems else 0)


if __name__ == "__main__":
    main()
