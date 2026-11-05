"""Independent checks on the v1.1 outputs: XML well-formedness, workbook vs seed, formula results
recomputed from sheet data, Appendix C coverage, and an entity-level diff against v1."""
import json, re, sys, zipfile
import xml.etree.ElementTree as ET

V1, J, X, MD = sys.argv[1:5]
v1 = json.load(open(V1, encoding="utf-8"))
s = json.load(open(J, encoding="utf-8"))
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships"}
fails = []


def check(c, msg):
    if not c:
        fails.append(msg)


# 1. every part parses
z = zipfile.ZipFile(X)
for n in z.namelist():
    if n.endswith(".xml") or n.endswith(".rels"):
        try:
            ET.fromstring(z.read(n))
        except ET.ParseError as e:
            fails.append(f"{n}: {e}")
print("parts:", len(z.namelist()))

# 2. read sheets back (cells by ref, formulas and cached values)
wb = ET.fromstring(z.read("xl/workbook.xml"))
rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))
relmap = {r.get("Id"): r.get("Target") for r in rels}
sheets = {}
for sh in wb.find("m:sheets", NS):
    target = "xl/" + relmap[sh.get("{%s}id" % NS["r"])]
    root = ET.fromstring(z.read(target))
    cells = {}
    for c in root.iter("{%s}c" % NS["m"]):
        f = c.find("m:f", NS)
        v = c.find("m:v", NS)
        t = c.find("m:is/m:t", NS)
        cells[c.get("r")] = {"f": f.text if f is not None else None,
                             "v": (t.text if t is not None else (v.text if v is not None else None))}
    sheets[sh.get("name")] = cells
print("sheets:", list(sheets))


def column(sheet, letter, start=2):
    out, r = [], start
    while f"{letter}{r}" in sheets[sheet] or f"A{r}" in sheets[sheet]:
        cell = sheets[sheet].get(f"{letter}{r}")
        out.append(cell["v"] if cell else None)
        r += 1
    return out


# 3. workbook content equals the seed
check(len(column("Categories", "A")) == s["counts"]["categories"], "Categories rows")
check(column("Brands", "B") == [b["name"] for b in s["brands"]], "Brands names/order")
check(column("Models", "C") == [m["name"] for m in s["models"]], "Models names/order")
check(column("Phone models", "B") == [v["name"] for v in s["picklists"]["phone_models"]["values"]], "Phone models")
pl_rows = [(a, c) for a, c in zip(column("Pick-lists", "A"), column("Pick-lists", "C"))]
want = [(k, v["name"]) for k, l in s["picklists"].items() if k != "phone_models" for v in l["values"]]
check(pl_rows == want, "Pick-lists rows")
check(column("Categories", "H")[0] == s["categories"][0]["example"]["prints"], "example column")

# 4. recompute every formula from the sheet data and compare with the cached value
def rng(ref):
    m = re.match(r"(?:'?([^'!]+)'?!)?\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)", ref)
    sh, c1, r1, c2, r2 = m.groups()
    assert c1 == c2
    return [sheets[sh].get(f"{c1}{r}", {}).get("v") for r in range(int(r1), int(r2) + 1)]


def evaluate(f):
    f = f.strip()
    def countif(m):
        vals = rng(m.group(1))
        return str(sum(1 for v in vals if v == m.group(2)))
    f = re.sub(r'COUNTIF\(([^,]+),"([^"]*)"\)', countif, f)
    f = re.sub(r"COUNTA\(([^)]+)\)", lambda m: str(sum(1 for v in rng(m.group(1)) if v not in (None, ""))), f)
    f = re.sub(r"SUM\(([^)]+)\)", lambda m: str(sum(float(v) for v in rng(m.group(1)) if v not in (None, ""))), f)
    return eval(f, {"__builtins__": {}})  # only digits and + - * ( ) remain


n = 0
for sh in ["Generators", "README"]:  # Generators first: README sums its cached shapes
    for ref, cell in sheets[sh].items():
        if cell["f"]:
            n += 1
            got = evaluate(cell["f"])
            check(float(got) == float(cell["v"]), f"{sh}!{ref}: formula gives {got}, cached {cell['v']}")
print("formulas recomputed:", n)

# 5. Appendix C mentions every brand, model, category, pick-list value and phone model
md = open(MD, encoding="utf-8").read()
for b in s["brands"]:
    check(f"| {b['name']} |" in md, f"appendix misses brand {b['name']}")
for m in s["models"]:
    check(m["name"] in md, f"appendix misses model {m['name']}")
for c in s["categories"]:
    check(c["name"] in md, f"appendix misses category {c['name']}")
for k, l in s["picklists"].items():
    for v in l["values"]:
        check(v["name"] in md, f"appendix misses {k} value {v['name']}")

# 6. entity-level diff against v1, printed for the record
b1 = {b["name"] for b in v1["brands"]}
b2 = {b["name"] for b in s["brands"]}
m1 = {(m["brand"], m["name"]) for m in v1["models"]}
m2 = {(next((x["name"] for x in s["brands"] if x["id"] == m["brand"]), None) if m["brand"] else None, m["name"])
      for m in s["models"]}
c1 = {c["code"] for c in v1["categories"]}
c2 = {c["code"] for c in s["categories"]}
print("brands removed:", sorted(b1 - b2), "| added:", sorted(b2 - b1))
print("models removed:", sorted(m1 - m2, key=str), "| added:", sorted(m2 - m1, key=str))
print("categories removed:", sorted(c1 - c2), "| added:", sorted(c2 - c1))
print("v1 sellable now parents:", sorted(c["code"] for c in s["categories"] if not c["sellable"] and c["code"] in c1 and
                                        next(x for x in v1["categories"] if x["code"] == c["code"])["sellable"]))
changed_names = [(x["code"], x["name"], next(c["name"] for c in s["categories"] if c["code"] == x["code"]))
                 for x in v1["categories"] if x["code"] in c2 and
                 x["name"] != next(c["name"] for c in s["categories"] if c["code"] == x["code"])]
print("category renames:", changed_names)
for k in v1["picklists"]:
    a = v1["picklists"][k]["values"]
    b = [v["name"] for v in s["picklists"][k]["values"]]
    if a != b:
        print(f"pick-list {k}: {[x for x in a if x not in b]} -> {[x for x in b if x not in a]}")
print("brands whose shown-first changed:",
      sorted(b["name"] for b in s["brands"]
             if set(b["shown_first_in"]) != set(next(x for x in v1["brands"] if x["name"] == b["name"])["shown_first_in"])))
print("\nFAILURES:" if fails else "\nALL CHECKS PASSED", *fails, sep="\n - ")
sys.exit(1 if fails else 0)
