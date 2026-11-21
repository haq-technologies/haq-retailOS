"""Checks that every answer from a shop's nginx carries the security headers (D123).

nginx drops a server's add_header lines in any location that has add_header lines of its own. So every
server block must include the security-headers snippet, and so must every location block that adds a
header itself. Standard library only.

    python3 -I tools/ci/check_nginx_headers.py apps/shop-server/docker/nginx/shop.conf
"""

import re
import sys

SNIPPET = "/etc/nginx/snippets/haq-security-headers.conf"


def tokens(text):
    """Words, quoted strings, braces and semicolons, with comments removed."""
    pattern = re.compile(r'"(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\'|[{};]|[^\s{};"\']+')
    for line in text.splitlines():
        line = re.sub(r'(^|\s)#.*$', '', line)
        yield from pattern.findall(line)


def blocks(text):
    """Each block as (its opening words, its own directives as lists of words), outermost first."""
    stack = [("main", [])]
    found = []
    words = []
    for token in tokens(text):
        if token == "{":
            stack.append((" ".join(words), []))
            words = []
        elif token == ";":
            stack[-1][1].append(words)
            words = []
        elif token == "}":
            found.append(stack.pop())
            words = []
        else:
            words.append(token)
    return found


def main(path):
    problems = []
    for head, directives in blocks(open(path, encoding="utf-8").read()):
        includes = any(d[:2] == ["include", SNIPPET] for d in directives)
        adds = [d for d in directives if d and d[0] == "add_header"]
        if head.startswith("server") and not any(d[:2] == ["listen", "127.0.0.1:8081"] for d in directives) and not includes:
            problems.append(f"{head}: the server block does not include {SNIPPET}")
        elif head.startswith("location") and adds and not includes:
            problems.append(f"{head}: adds its own headers, so it drops the server's; include {SNIPPET} here too")
        for d in adds:
            if len(d) > 1 and d[1].lower() in {"strict-transport-security", "x-content-type-options", "x-frame-options", "referrer-policy"}:
                problems.append(f"{head}: sets {d[1]} by hand; the snippet sets it")
    for problem in problems:
        print(f"::error file={path}::{problem}")
    if not problems:
        print(f"{path}: every server and every location that adds headers includes the security headers.")
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
