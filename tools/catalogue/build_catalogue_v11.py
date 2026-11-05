"""Build HAQ Retail OS starter catalogue v1.1 from the locked v1 seed plus the decisions of 5 Nov 2026.

Usage (from the repository root):
  python3 -I tools/catalogue/build_catalogue_v11.py docs/archive/starter-catalogue-v1.json \
      docs/catalogue/starter-catalogue-v1.1.json docs/catalogue/starter-catalogue-v1.1.xlsx \
      docs/catalogue/appendix-c-starter-catalogue-v1.1.md

Every change is traceable to a review item (C1-C13, R1, R2) or an answer (Q16-Q19, F4, F5).
The JSON is the master; the workbook and Appendix C are generated from it.
"""
import json, re, sys, zipfile, collections
from xml.sax.saxutils import escape

V1_PATH, OUT_JSON, OUT_XLSX, OUT_MD = sys.argv[1:5]
TODAY = "2026-11-05"
v1 = json.load(open(V1_PATH, encoding="utf-8"))


def slug(s):
    s = s.lower().replace("+", " plus ")
    s = re.sub(r"[^a-z0-9]+", "-", s)
    return re.sub(r"-+", "-", s).strip("-")


def unspace_unit(v):  # C11: "1 m" -> "1m"
    return re.sub(r"^(\d+(?:\.\d+)?) ([A-Za-z]+)$", r"\1\2", v)


# ------------------------------------------------------------------ pick-lists
def plist(key, label, names, origin, aliases=None, note=None, rename=None):
    vals = []
    for n in names:
        shown = (rename or {}).get(n, n)
        v = {"id": f"{key}.{slug(shown)}", "name": shown}
        al = (aliases or {}).get(shown)
        if al:
            v["aliases"] = al
        vals.append(v)
    d = {"label": label, "origin": origin, "values": vals}
    if note:
        d["note"] = note
    return d


P = v1["picklists"]
picklists = collections.OrderedDict()
picklists["connector_pairs"] = plist(
    "connector_pairs", "Connector pair", P["connector_pairs"]["values"], P["connector_pairs"]["origin"],
    aliases={"USB-A to Lightning": ["iphone charger", "iphone"],
             "Type-C to Lightning": ["iphone charger", "iphone"]})  # C4 + Q17
picklists["cable_lengths"] = plist(
    "cable_lengths", "Cable length", [unspace_unit(v) for v in P["cable_lengths"]["values"]],
    P["cable_lengths"]["origin"])  # C11
picklists["cable_rated_power"] = plist("cable_rated_power", "Cable rated power", P["cable_rated_power"]["values"], P["cable_rated_power"]["origin"])
picklists["wattages"] = plist("wattages", "Wattage", P["wattages"]["values"], P["wattages"]["origin"],
                              note="Shared by charger heads, complete chargers, car chargers and wireless chargers (Q19)")
picklists["capacities_mah"] = plist("capacities_mah", "Capacity (mAh)", P["capacities_mah"]["values"], P["capacities_mah"]["origin"])
picklists["colours"] = plist("colours", "Colour", P["colours"]["values"], P["colours"]["origin"],
                             rename={"Gray": "Grey"}, aliases={"Grey": ["gray"]},
                             note="A note on the sale line, never a separate item")  # C12
picklists["case_types"] = plist("case_types", "Pouch type", P["case_types"]["values"], P["case_types"]["origin"],
                                rename={"Wallet flip": "Wallet Flip"})  # C11 title case for printed values
picklists["plug_types"] = plist("plug_types", "Plug type", P["plug_types"]["values"], P["plug_types"]["origin"])
picklists["screen_protector_types"] = plist("screen_protector_types", "Protector type", P["screen_protector_types"]["values"],
                                            P["screen_protector_types"]["origin"], rename={"Tempered glass": "Tempered Glass"})
picklists["holder_kinds"] = plist("holder_kinds", "Holder kind", P["holder_kinds"]["values"], P["holder_kinds"]["origin"])
picklists["memory_sizes_gb"] = plist("memory_sizes_gb", "Capacity (GB)", P["memory_sizes_gb"]["values"], P["memory_sizes_gb"]["origin"])
v1_adapters = next(c for c in v1["categories"] if c["code"] == "adapters")
picklists["adapter_connectors"] = plist("adapter_connectors", "Adapter connector", v1_adapters["connector_values"],
                                        "assistant_suggestion (v1 inline values)")  # C10
picklists["connectivity"] = plist("connectivity", "Connectivity", ["Wired", "Wireless", "Bluetooth"], "assistant_suggestion")  # C13
picklists["phone_families"] = plist("phone_families", "Phone family", P["phone_families"]["values"], P["phone_families"]["origin"],
                                    note="First level of the phone-model picker; family names are never printed")

# C2: phone models, second level of the picker. Public model names, drafted from memory as of mid-2025.
PHONES = collections.OrderedDict([
    ("iPhone", ["iPhone 6", "iPhone 6 Plus", "iPhone 6s", "iPhone 6s Plus", "iPhone 7", "iPhone 7 Plus", "iPhone 8",
                "iPhone 8 Plus", "iPhone X", "iPhone XR", "iPhone XS", "iPhone XS Max", "iPhone SE 2020", "iPhone 11",
                "iPhone 11 Pro", "iPhone 11 Pro Max", "iPhone 12 mini", "iPhone 12", "iPhone 12 Pro", "iPhone 12 Pro Max",
                "iPhone 13 mini", "iPhone 13", "iPhone 13 Pro", "iPhone 13 Pro Max", "iPhone SE 2022", "iPhone 14",
                "iPhone 14 Plus", "iPhone 14 Pro", "iPhone 14 Pro Max", "iPhone 15", "iPhone 15 Plus", "iPhone 15 Pro",
                "iPhone 15 Pro Max", "iPhone 16", "iPhone 16 Plus", "iPhone 16 Pro", "iPhone 16 Pro Max", "iPhone 16e",
                "iPhone 17", "iPhone Air", "iPhone 17 Pro", "iPhone 17 Pro Max"]),
    ("Samsung Galaxy A", ["Samsung Galaxy " + m for m in [
        "A02s", "A03", "A03s", "A04", "A04e", "A04s", "A05", "A05s", "A06", "A10", "A10s", "A11", "A12", "A13", "A14",
        "A15", "A16", "A20", "A20s", "A21s", "A22", "A23", "A24", "A25", "A26", "A30", "A30s", "A31", "A32", "A33", "A34",
        "A35", "A36", "A50", "A51", "A52", "A52s", "A53", "A54", "A55", "A56", "A70", "A71", "A72", "A73"]]),
    ("Samsung Galaxy S", ["Samsung Galaxy " + m for m in [
        "S8", "S8+", "S9", "S9+", "S10e", "S10", "S10+", "S20", "S20+", "S20 Ultra", "S20 FE", "S21", "S21+", "S21 Ultra",
        "S21 FE", "S22", "S22+", "S22 Ultra", "S23", "S23+", "S23 Ultra", "S23 FE", "S24", "S24+", "S24 Ultra", "S24 FE",
        "S25", "S25+", "S25 Ultra", "S25 Edge", "S25 FE"]]),
    ("Samsung Galaxy M", ["Samsung Galaxy " + m for m in [
        "M12", "M13", "M14", "M15", "M21", "M22", "M23", "M31", "M32", "M33", "M34", "M35", "M51", "M52", "M53", "M54", "M55"]]),
    ("Tecno Spark", ["Tecno " + m for m in [
        "Spark 5", "Spark 5 Pro", "Spark 6", "Spark 6 Go", "Spark 7", "Spark 7P", "Spark 7 Pro", "Spark 8", "Spark 8C",
        "Spark 8P", "Spark 8 Pro", "Spark 9", "Spark 9 Pro", "Spark 9T", "Spark 10", "Spark 10C", "Spark 10 Pro",
        "Spark 20", "Spark 20C", "Spark 20 Pro", "Spark 20 Pro+", "Spark 30", "Spark 30C", "Spark 30 Pro",
        "Spark Go 2022", "Spark Go 2023", "Spark Go 2024"]]),
    ("Tecno Camon", ["Tecno " + m for m in [
        "Camon 12", "Camon 12 Pro", "Camon 15", "Camon 15 Pro", "Camon 16", "Camon 16 Premier", "Camon 17", "Camon 17P",
        "Camon 17 Pro", "Camon 18", "Camon 18P", "Camon 18 Premier", "Camon 19", "Camon 19 Neo", "Camon 19 Pro",
        "Camon 20", "Camon 20 Pro", "Camon 20 Premier", "Camon 30", "Camon 30 Pro", "Camon 30 Premier", "Camon 40",
        "Camon 40 Pro", "Camon 40 Premier"]]),
    ("Tecno Pova", ["Tecno " + m for m in [
        "Pova", "Pova 2", "Pova 3", "Pova 4", "Pova 4 Pro", "Pova 5", "Pova 5 Pro", "Pova 6 Pro", "Pova 6 Neo",
        "Pova Neo", "Pova Neo 2", "Pova Neo 3"]]),
    ("Tecno Phantom", ["Tecno " + m for m in [
        "Phantom 9", "Phantom X", "Phantom X2", "Phantom X2 Pro", "Phantom V Fold", "Phantom V Flip",
        "Phantom V Fold2", "Phantom V Flip2"]]),
    ("Infinix Hot", ["Infinix " + m for m in [
        "Hot 8", "Hot 9", "Hot 9 Play", "Hot 10", "Hot 10 Play", "Hot 10i", "Hot 10S", "Hot 11", "Hot 11 Play", "Hot 11S",
        "Hot 12", "Hot 12 Play", "Hot 12i", "Hot 20", "Hot 20i", "Hot 20 Play", "Hot 20S", "Hot 30", "Hot 30i",
        "Hot 30 Play", "Hot 40", "Hot 40i", "Hot 40 Pro", "Hot 50", "Hot 50i", "Hot 50 Pro", "Hot 50 Pro+"]]),
    ("Infinix Note", ["Infinix " + m for m in [
        "Note 7", "Note 8", "Note 8i", "Note 10", "Note 10 Pro", "Note 11", "Note 11 Pro", "Note 11S", "Note 12",
        "Note 12 Pro", "Note 12 G96", "Note 12 VIP", "Note 30", "Note 30 Pro", "Note 30 VIP", "Note 40", "Note 40 Pro",
        "Note 40 Pro+", "Note 40S", "Note 50", "Note 50 Pro", "Note 50 Pro+"]]),
    ("Infinix Smart", ["Infinix " + m for m in [
        "Smart 4", "Smart 5", "Smart 6", "Smart 6 HD", "Smart 6 Plus", "Smart 7", "Smart 7 HD", "Smart 8", "Smart 8 HD",
        "Smart 8 Plus", "Smart 8 Pro", "Smart 9", "Smart 9 HD"]]),
    ("Infinix Zero and GT", ["Infinix " + m for m in [
        "Zero 8", "Zero X", "Zero X Pro", "Zero 20", "Zero Ultra", "Zero 30", "Zero 40", "Zero Flip", "GT 10 Pro", "GT 20 Pro"]]),
    ("itel A, P and S", ["itel " + m for m in [
        "A05s", "A18", "A25", "A26", "A27", "A48", "A49", "A50", "A56", "A58", "A60", "A60s", "A70", "P17", "P36", "P37",
        "P38", "P40", "P55", "S16", "S18", "S23", "S24"]]),
    ("Xiaomi Redmi", ["Redmi " + m for m in [
        "9", "9A", "9C", "9T", "10", "10A", "10C", "12", "12C", "13", "13C", "14C", "A1", "A2", "A3", "Note 8",
        "Note 8 Pro", "Note 9", "Note 9 Pro", "Note 9S", "Note 10", "Note 10 Pro", "Note 10S", "Note 11", "Note 11 Pro",
        "Note 11S", "Note 12", "Note 12 Pro", "Note 13", "Note 13 Pro", "Note 13 Pro+", "Note 14", "Note 14 Pro",
        "Note 14 Pro+"]]),
    ("Poco", ["Poco " + m for m in [
        "X3 NFC", "X3 Pro", "X4 Pro", "X5", "X5 Pro", "X6", "X6 Pro", "X7", "X7 Pro", "F3", "F4", "F5", "F5 Pro", "F6",
        "F6 Pro", "F7 Pro", "F7 Ultra", "M3", "M4 Pro", "M5", "M6 Pro", "C40", "C51", "C61", "C65", "C75"]]),
    ("Oppo", ["Oppo " + m for m in [
        "A5s", "A12", "A15", "A16", "A17", "A18", "A38", "A54", "A57", "A58", "A77", "A78", "A79", "A3x", "Reno5",
        "Reno6", "Reno7", "Reno8", "Reno8 T", "Reno10", "Reno11", "Reno11 F", "Reno12", "Reno12 F", "Reno13", "Reno13 F"]]),
    ("Realme", ["Realme " + m for m in [
        "C11", "C12", "C15", "C21", "C21Y", "C25", "C30", "C31", "C33", "C35", "C51", "C53", "C55", "C61", "C63", "C65",
        "C67", "C75", "Note 50", "Note 60", "8", "9", "10", "11", "12", "12 Pro", "12 Pro+", "13", "13 Pro", "13 Pro+"]]),
    ("Huawei and Honor", ["Huawei " + m for m in [
        "Y6p", "Y7a", "Y9a", "Nova 7i", "Nova 9", "Nova 10", "Nova 11", "Nova 12", "P30", "P30 Lite", "P40 Lite"]]
     + ["Honor " + m for m in ["X5", "X6", "X7", "X7a", "X7b", "X8", "X8a", "X8b", "X9a", "X9b", "X9c", "90", "200"]]),
    ("Nokia and HMD", ["Nokia " + m for m in [
        "C1", "C2", "C10", "C12", "C20", "C21", "C21 Plus", "C22", "C30", "C31", "C32", "G10", "G11", "G20", "G21", "G22",
        "G42", "X10", "X20"]] + ["HMD " + m for m in ["Pulse", "Pulse Plus", "Pulse Pro", "Skyline", "Fusion", "Arc"]]),
])
fam_id = {v["name"]: v["id"] for v in picklists["phone_families"]["values"]}
phone_vals = []
for fam, names in PHONES.items():
    for n in names:
        phone_vals.append({"id": f"phone_models.{slug(n)}", "name": n, "family": fam_id[fam]})
picklists["phone_models"] = {
    "label": "Phone model", "origin": "assistant_draft", "group_by": "phone_families", "values": phone_vals,
    "note": ("Public model names drafted from memory as of mid-2025. Verify against the shelf and supplier lists. "
             "A cashier can type a model that is missing; it prints as typed and joins the list once a manager or "
             "the owner approves it.")}

# ------------------------------------------------------------------ brands
SHORT_CODES = {"HF", "AI", "LP", "BM", "DV", "JZ", "EX", "UWO"}
brands = []
for b in v1["brands"]:
    if b["name"] in ("iPhone Charger", "PRO"):  # C4/Q17 and C5/Q18/F5
        continue
    sfi = []
    for c in b["shown_first_in"]:
        if c == "smartwatches":
            sfi += ["smartwatch", "band"]  # C1
        elif c in ("cases", "screen_protectors") and b["name"] in ("Apple", "Samsung"):
            continue  # C3 + Q16: on pouches "Apple" means the phone, not the maker
        else:
            sfi.append(c)
    entry = {"id": "brand." + slug(b["name"]), "name": b["name"], "aliases": b.get("alias", []),
             "shown_first_in": sfi, "provenance": b["provenance"], "status": b["status"]}
    if b["name"] in SHORT_CODES:
        entry["note"] = "Short code; confirm against the real product at the shelf walk (C5)"
    elif b["name"] == "DataCable":
        entry["note"] = "Printed label on the packaging (owner, 5 Nov 2026)"
    elif b["name"] == "Baseno":
        entry["note"] = "Kept exactly as written"
    brands.append(entry)
NO_BRAND = {"id": "brand.no-brand", "name": v1["no_brand_value"]}
brand_by_id = {b["id"]: b for b in brands}
brand_by_id[NO_BRAND["id"]] = NO_BRAND

# ------------------------------------------------------------------ models
models = []
for m in v1["models"]:
    cats = ["smartwatch" if c == "smartwatches" else c for c in m["categories"]]
    owner = slug(m["brand"]) if m["brand"] else "no-brand"
    models.append({"id": f"model.{owner}.{slug(m['name'])}",
                   "brand": ("brand." + slug(m["brand"])) if m["brand"] else None,
                   "name": m["name"], "categories": cats, "note": m["note"]})
models.append({"id": "model.no-brand.pro", "brand": None, "name": "Pro", "categories": ["earbuds"],
               "note": "Product name sold as earpods (owner, 5 Nov 2026); moved from Brands; not the same product as Pro 5s"})

# ------------------------------------------------------------------ categories
BRAND = {"key": "brand", "label": "Brand", "type": "brand"}
MADE_BY = {"key": "brand", "label": "Made by", "type": "brand", "default": NO_BRAND["id"]}  # C3
PHONE = {"key": "phone_model", "label": "Phone model", "type": "picklist", "list": "phone_models",
         "group_by": "phone_families", "allow_typed": True}  # C2


def pl(key, label, lst):
    return {"key": key, "label": label, "type": "picklist", "list": lst}


def tx(key, label):
    return {"key": key, "label": label, "type": "text"}


def cat(code, name, word, facets, drill, order, example_values, prints, parent=None, aliases=None):
    c = {"code": code, "name": name, "parent": parent, "sellable": True, "word": word, "facets": facets,
         "drill_order": drill, "name_order": order, "example": {"values": example_values, "prints": prints}}
    if aliases:
        c["aliases"] = aliases
    return c


def parent(code, name):
    return {"code": code, "name": name, "parent": None, "sellable": False, "word": None, "facets": [],
            "drill_order": [], "name_order": [], "example": None}


W = "{word}"
CP = pl("connector_pair", "Connector pair", "connector_pairs")
WATT = pl("wattage", "Wattage", "wattages")
categories = [
    cat("cables", "Cables", "Cable",
        [BRAND, CP, pl("length", "Length", "cable_lengths"), pl("rated_power", "Rated power", "cable_rated_power")],
        ["connector_pair", "length", "brand", "rated_power"], ["brand", "connector_pair", W, "length", "rated_power"],
        {"brand": "brand.anker", "connector_pair": "connector_pairs.type-c-to-lightning", "length": "cable_lengths.1m"},
        "Anker Type-C to Lightning Cable 1m"),
    parent("wall_chargers", "Wall chargers"),
    cat("charger_head", "Charger head", "Charger Head", [BRAND, WATT], ["wattage", "brand"], ["brand", W, "wattage"],
        {"brand": "brand.otek", "wattage": "wattages.20w"}, "Otek Charger Head 20W", parent="wall_chargers",
        aliases=["charger head", "wall charger without cable", "adapter"]),
    cat("complete_charger", "Complete charger", "Complete Charger", [BRAND, CP, WATT],
        ["connector_pair", "wattage", "brand"], ["brand", "connector_pair", W, "wattage"],
        {"brand": "brand.oraimo", "connector_pair": "connector_pairs.type-c-to-lightning", "wattage": "wattages.20w"},
        "Oraimo Type-C to Lightning Complete Charger 20W", parent="wall_chargers",
        aliases=["complete charger", "charger and cable", "full charger"]),
    cat("car_chargers", "Car chargers", "Car Charger", [BRAND, tx("model", "Model"), WATT],
        ["wattage", "brand", "model"], ["brand", "model", W, "wattage"],
        {"brand": "brand.kly", "wattage": "wattages.12w"}, "KLY Car Charger 12W"),
    cat("wireless_chargers", "Wireless chargers", "Wireless Charger", [BRAND, tx("model", "Model"), WATT],
        ["brand", "model", "wattage"], ["brand", "model", W, "wattage"],
        {"brand": "brand.baseus", "wattage": "wattages.20w"}, "Baseus Wireless Charger 20W"),  # C7 + Q19
    cat("power_banks", "Power banks", "Power Bank",
        [BRAND, tx("model", "Model"), pl("capacity", "Capacity", "capacities_mah")],
        ["brand", "capacity", "model"], ["brand", "model", W, "capacity"],
        {"brand": "brand.oraimo", "model": "Traveler 4", "capacity": "capacities_mah.20000mah"},
        "Oraimo Traveler 4 Power Bank 20000mAh"),
    cat("earbuds", "Earbuds and earpods", "Earbuds", [BRAND, tx("model", "Model")], ["brand", "model"],
        ["brand", "model", W], {"brand": "brand.oraimo", "model": "FreePods 3C"}, "Oraimo FreePods 3C Earbuds",
        aliases=["earpods"]),  # F5
    cat("wired_earphones", "Wired earphones and handsfree", "Earphones",
        [BRAND, tx("model", "Model"), pl("plug", "Plug type", "plug_types")], ["brand", "model", "plug"],
        ["brand", "model", W, "plug"], {"model": "Q10", "plug": "plug_types.3-5mm"}, "Q10 Earphones 3.5mm"),
    cat("neckband", "Neckband earphones", "Neckband Earphones", [BRAND, tx("model", "Model")], ["brand", "model"],
        ["brand", "model", W], {"brand": "brand.oraimo"}, "Oraimo Neckband Earphones"),
    cat("headphones", "Headphones", "Headphones", [BRAND, tx("model", "Model")], ["brand", "model"],
        ["brand", "model", W], {"brand": "brand.jbl"}, "JBL Headphones"),
    cat("bluetooth_speakers", "Bluetooth speakers", "Bluetooth Speaker", [BRAND, tx("model", "Model")],
        ["brand", "model"], ["brand", "model", W], {"brand": "brand.zealot", "model": "S32"},
        "Zealot S32 Bluetooth Speaker"),
    parent("smartwatches", "Smartwatches and bands"),  # C1
    cat("smartwatch", "Smartwatch", "Smartwatch", [BRAND, tx("model", "Model")], ["brand", "model"],
        ["brand", "model", W], {"brand": "brand.infinix", "model": "XWatch 3 WE"}, "Infinix XWatch 3 WE Smartwatch",
        parent="smartwatches"),
    cat("band", "Band", "Band", [BRAND, tx("model", "Model")], ["brand", "model"], ["brand", "model", W],
        {"brand": "brand.huawei"}, "Huawei Band", parent="smartwatches"),
    cat("cases", "Phone pouches and covers", "Pouch", [MADE_BY, PHONE, pl("case_type", "Pouch type", "case_types")],
        ["phone_model", "case_type", "brand"], ["brand", "phone_model", "case_type", W],
        {"phone_model": "phone_models.iphone-11", "case_type": "case_types.silicone"}, "iPhone 11 Silicone Pouch",
        aliases=["case", "phone case", "cover"]),  # F4 + C2 + C3
    cat("screen_protectors", "Screen protectors", "Screen Protector",
        [MADE_BY, PHONE, pl("type", "Protector type", "screen_protector_types")],
        ["phone_model", "type", "brand"], ["brand", "phone_model", "type", W],
        {"phone_model": "phone_models.tecno-spark-20", "type": "screen_protector_types.tempered-glass"},
        "Tecno Spark 20 Tempered Glass Screen Protector"),
    cat("phone_holders", "Phone holders and stands", "Phone Holder", [BRAND, pl("kind", "Kind", "holder_kinds")],
        ["kind", "brand"], ["brand", "kind", W], {"brand": "brand.baseus", "kind": "holder_kinds.car"},
        "Baseus Car Phone Holder"),
    parent("memory", "Memory cards and flash drives"),  # C1
    cat("memory_card", "Memory card", "Memory Card", [BRAND, pl("capacity", "Capacity", "memory_sizes_gb")],
        ["capacity", "brand"], ["brand", W, "capacity"], {"brand": "brand.sony", "capacity": "memory_sizes_gb.64gb"},
        "Sony Memory Card 64GB", parent="memory"),
    cat("flash_drive", "Flash drive", "Flash Drive", [BRAND, pl("capacity", "Capacity", "memory_sizes_gb")],
        ["capacity", "brand"], ["brand", W, "capacity"], {"capacity": "memory_sizes_gb.32gb"}, "Flash Drive 32GB",
        parent="memory"),
    cat("batteries", "Replacement batteries", "Battery", [MADE_BY, PHONE], ["phone_model", "brand"],
        ["brand", "phone_model", W], {"phone_model": "phone_models.tecno-spark-5"}, "Tecno Spark 5 Battery"),
    parent("selfie_lights_tripods", "Selfie sticks, ring lights, tripods"),  # C1
    cat("selfie_stick", "Selfie stick", "Selfie Stick", [BRAND, tx("size", "Size")], ["brand", "size"],
        ["brand", W, "size"], {}, "Selfie Stick", parent="selfie_lights_tripods"),
    cat("ring_light", "Ring light", "Ring Light", [BRAND, tx("size", "Size")], ["size", "brand"],
        ["brand", W, "size"], {"size": "10 inch"}, "Ring Light 10 inch", parent="selfie_lights_tripods"),
    cat("tripod", "Tripod", "Tripod", [BRAND, tx("size", "Size")], ["size", "brand"], ["brand", W, "size"], {},
        "Tripod", parent="selfie_lights_tripods"),
    parent("adapters", "Adapters (OTG, AUX, splitters)"),  # C1 + C10
    cat("otg_adapter", "OTG adapter", "OTG Adapter", [pl("connector", "Connector", "adapter_connectors")],
        ["connector"], [W, "connector"], {"connector": "adapter_connectors.type-c"}, "OTG Adapter Type-C",
        parent="adapters"),
    cat("aux_adapter", "AUX adapter", "AUX Adapter", [pl("connector", "Connector", "adapter_connectors")],
        ["connector"], [W, "connector"], {"connector": "adapter_connectors.lightning"}, "AUX Adapter Lightning",
        parent="adapters"),
    cat("splitter", "Splitter", "Splitter", [pl("connector", "Connector", "adapter_connectors")], ["connector"],
        [W, "connector"], {"connector": "adapter_connectors.3-5mm"}, "Splitter 3.5mm", parent="adapters"),
    parent("computer_accessories", "Computer accessories (mouse, keyboard, hubs)"),  # C1 + C13
    cat("mouse", "Mouse", "Mouse", [BRAND, pl("connectivity", "Connectivity", "connectivity"), tx("model", "Model")],
        ["connectivity", "brand", "model"], ["brand", "connectivity", W, "model"],
        {"connectivity": "connectivity.wireless"}, "Wireless Mouse", parent="computer_accessories"),
    cat("keyboard", "Keyboard", "Keyboard",
        [BRAND, pl("connectivity", "Connectivity", "connectivity"), tx("model", "Model")],
        ["connectivity", "brand", "model"], ["brand", "connectivity", W, "model"],
        {"connectivity": "connectivity.wired"}, "Wired Keyboard", parent="computer_accessories"),
    cat("usb_hub", "USB hub", "USB Hub", [BRAND, tx("model", "Model")], ["brand", "model"], ["brand", W, "model"],
        {}, "USB Hub", parent="computer_accessories"),
]
cat_by_code = {c["code"]: c for c in categories}

# ------------------------------------------------------------------ R2 generators (replace the 127 stored lines)
generators = [
    {"category": "cables", "combine": ["connector_pair", "length"], "blank_allowed": ["length"], "exclude": {}},
    {"category": "charger_head", "combine": ["wattage"], "blank_allowed": [], "exclude": {}},
    {"category": "complete_charger", "combine": ["connector_pair", "wattage"], "blank_allowed": [],
     "exclude": {"connector_pair": ["connector_pairs.3-in-1", "connector_pairs.3-5mm-aux"]}},
    {"category": "car_chargers", "combine": ["wattage"], "blank_allowed": [], "exclude": {}},
    {"category": "power_banks", "combine": ["capacity"], "blank_allowed": [], "exclude": {}},
]

value_by_id = {}
for key, lst in picklists.items():
    for v in lst["values"]:
        value_by_id[v["id"]] = v


def display_name(category, values):
    """The one name routine (spec section 6): name_order, blank parts skipped, No brand never printed."""
    parts = []
    facets = {f["key"]: f for f in category["facets"]}
    for tok in category["name_order"]:
        if tok == W:
            parts.append(category["word"])
            continue
        v = values.get(tok)
        if not v or v == NO_BRAND["id"]:
            continue
        f = facets[tok]
        if f["type"] == "brand":
            parts.append(brand_by_id[v]["name"])
        elif f["type"] == "picklist":
            parts.append(value_by_id[v]["name"])
        else:
            parts.append(v)
    return " ".join(parts)


def generated_shapes(g):
    c = cat_by_code[g["category"]]
    facets = {f["key"]: f for f in c["facets"]}
    axes = []
    for k in g["combine"]:
        ids = [v["id"] for v in picklists[facets[k]["list"]]["values"] if v["id"] not in g["exclude"].get(k, [])]
        if k in g["blank_allowed"]:
            ids = [None] + ids
        axes.append((k, ids))
    shapes = [{}]
    for k, ids in axes:
        shapes = [dict(s, **({k: i} if i else {})) for s in shapes for i in ids]
    return [display_name(c, s) for s in shapes]


for g in generators:
    g["shapes"] = len(generated_shapes(g))

# ------------------------------------------------------------------ rules
rules = collections.OrderedDict(v1["rules"])
rules.pop("generated_lines_need_prune_review", None)
rules["generated_lines_built_on_demand"] = True  # R2
rules["every_sellable_category_has_a_word"] = True  # C1
rules["new_phone_model"] = ("Cashier may type a phone model that is not in the list. It prints as typed, stays on that "
                            "item, and joins the list when a manager or the owner approves it.")  # C2
rules["who_adds_phone_model_to_list"] = ["manager", "owner"]
rules["made_by_defaults_to_no_brand"] = ["cases", "screen_protectors", "batteries"]  # C3
rules["units_written_without_space"] = True  # C11

# ------------------------------------------------------------------ changelog
changelog = [
    ("C1", "Four word-less categories, plus Smartwatches, became parents with 13 sellable children",
     "Memory card, Flash drive; Selfie stick, Ring light, Tripod; OTG adapter, AUX adapter, Splitter; Mouse, Keyboard, "
     "USB hub; Smartwatch, Band. Every sellable category now has a category word, so no receipt line can print blank. "
     "The inline kind lists and the kind_or_word token are retired. The top level still shows 19 tiles."),
    ("C2", "Phone models became a two-level pick-list used by pouches, screen protectors and batteries",
     f"19 families, {len(phone_vals)} models drafted from public names; verify at the shelf walk. A missing model can be "
     "typed at the counter; it waits for approval like a typed brand."),
    ("C3 + Q16", "\"Made by\" replaces Brand on pouches, protectors and batteries, defaulting to No brand",
     "The phone's maker now comes from the phone model. Apple and Samsung are no longer shown first there, because on "
     "pouches the owner uses them to mean the phone."),
    ("C4 + Q17", "\"iPhone Charger\" left the brand list",
     "It is a description, so \"iphone charger\" and \"iphone\" became search words on the two Lightning connector "
     "pairs. DataCable stays a brand: it is printed on the packaging."),
    ("C5 + C6 + F5", "PRO moved from Brands to Models",
     "Pro is an earpods product (No brand, Earbuds), separate from Pro 5s. J7 and car mp3 s8 stay under Car chargers "
     "and print as typed."),
    ("C7 + Q19", "Wireless chargers keep the shared wattage list", "The example now uses 20W; 15W was never in the list."),
    ("C9", "Every entry has a permanent ID, and the file has a schema_version",
     "IDs never change when a name changes, so a later catalogue pull can tell a rename from a new entry."),
    ("C10", "The adapters' inline connector values became the named list adapter_connectors", ""),
    ("C11", "Units are written without a space, and printed values use title case",
     "0.25m, 1m, 1.5m, 2m, 3m; Wallet Flip; Tempered Glass. Search treats \"1 m\" and \"1m\" as the same."),
    ("C12", "Gray became Grey", "\"gray\" stays a search word."),
    ("C13", "Mouse and Keyboard gained a Connectivity facet", "Wired, Wireless or Bluetooth, printed before the word: "
     "\"Wireless Mouse\"."),
    ("F4", "Cases print as \"Pouch\", with the pouch type before the word",
     "\"iPhone 11 Silicone Pouch\". The category is now \"Phone pouches and covers\"; \"case\", \"phone case\" and "
     "\"cover\" are search words. Screen protectors follow the same order (\"Tecno Spark 20 Tempered Glass Screen "
     "Protector\"); confirm with the owner."),
    ("F5", "\"earpods\" became a search word for Earbuds", ""),
    ("R1", "Each category has a drill order for the tap-through tiles",
     "Proposed defaults; the cashier timing test at G1 confirms them."),
    ("R2", "The 127 stored generated lines became 5 generator rules",
     "The same 127 shapes are offered at the counter and an item is created on its first sale, so there is no prune "
     "review at setup."),
]
still_open = [
    "C8: brands shown first for Wireless chargers, Phone holders, Memory cards and flash drives, Batteries, "
    "Selfie sticks, ring lights and tripods, and Computer accessories are assigned at the shelf walk.",
    "The 16 brands marked \"To confirm on the shelf walk\", and the short codes HF, AI, LP, BM, DV, JZ, EX and UWO.",
    "Oraimo \"Watch\" prints as \"Oraimo Watch Smartwatch\"; rename it if the owner prefers.",
    "Phone models are a first draft: add, rename or remove against the shelf and supplier lists.",
    "Drill orders are proposals until the cashier timing test at G1.",
]

# ------------------------------------------------------------------ assemble seed
seed = collections.OrderedDict()
seed["schema_version"] = 2
seed["version"] = "1.1"
seed["status"] = "Draft for the shop owner to re-confirm"
seed["based_on"] = {"version": v1["version"], "locked": v1["locked"]}
seed["drafted"] = TODAY
seed["locked"] = None
seed["spec"] = "HAQ Retail OS Spec v1.5 (docs/spec/retail-os-spec-v1.5.md)"
seed["product"] = v1["product"]
seed["currency"] = v1["currency"]
seed["money_unit"] = v1["money_unit"]
seed["counts"] = {
    "categories": len(categories),
    "sellable_categories": sum(c["sellable"] for c in categories),
    "parent_categories": sum(not c["sellable"] for c in categories),
    "brands": len(brands),
    "brands_plus_no_brand": len(brands) + 1,
    "models": len(models),
    "picklists": len(picklists),
    "phone_families": len(picklists["phone_families"]["values"]),
    "phone_models": len(phone_vals),
    "generator_rules": len(generators),
    "generated_shapes_on_demand": sum(g["shapes"] for g in generators),
}
seed["rules"] = rules
seed["picklists"] = picklists
seed["no_brand"] = NO_BRAND
seed["brands"] = brands
seed["categories"] = categories
seed["models"] = models
seed["generators"] = generators
seed["changelog"] = [{"ref": r, "change": c, "detail": d} for r, c, d in changelog]
seed["still_open"] = still_open

# ------------------------------------------------------------------ validation (fails the build on any defect)
errors = []


def check(cond, msg):
    if not cond:
        errors.append(msg)


all_ids = [c["code"] for c in categories] + [b["id"] for b in brands] + [NO_BRAND["id"]] + [m["id"] for m in models] \
    + [v["id"] for lst in picklists.values() for v in lst["values"]]
dupes = [i for i, n in collections.Counter(all_ids).items() if n > 1]
check(not dupes, f"duplicate ids: {dupes}")
for c in categories:
    if c["parent"]:
        p = cat_by_code.get(c["parent"])
        check(p is not None and not p["sellable"], f"{c['code']}: parent {c['parent']} missing or sellable")
    if not c["sellable"]:
        check(c["word"] is None and not c["facets"], f"{c['code']}: a parent must have no word and no facets")
        check(any(x["parent"] == c["code"] for x in categories), f"{c['code']}: parent with no children")
        continue
    keys = [f["key"] for f in c["facets"]]
    check(len(keys) == len(set(keys)), f"{c['code']}: duplicate facet keys")
    check(bool(c["word"]), f"{c['code']}: sellable category without a word (C1)")
    for f in c["facets"]:
        if f["type"] == "picklist":
            check(f["list"] in picklists, f"{c['code']}.{f['key']}: unknown list {f['list']}")
    for tok in c["name_order"]:
        check(tok == W or tok in keys, f"{c['code']}: name_order token {tok} is not a facet")
    check(sorted(c["drill_order"]) == sorted(keys), f"{c['code']}: drill_order must list every facet once")
    check(display_name(c, {}).strip() != "", f"{c['code']}: prints an empty name with only the category set")
    ex = c["example"]
    for k, v in ex["values"].items():
        f = next((x for x in c["facets"] if x["key"] == k), None)
        check(f is not None, f"{c['code']}: example uses unknown facet {k}")
        if f and f["type"] == "picklist":
            check(v in value_by_id and v.startswith(f["list"] + "."), f"{c['code']}: example value {v} not in {f['list']}")
        if f and f["type"] == "brand":
            check(v in brand_by_id, f"{c['code']}: example brand {v} unknown")
    check(display_name(c, ex["values"]) == ex["prints"],
          f"{c['code']}: example prints '{display_name(c, ex['values'])}', expected '{ex['prints']}'")
lower_brands = collections.Counter(b["name"].lower() for b in brands)
check(all(n == 1 for n in lower_brands.values()), "brand names not unique ignoring case")
check(not {"iphone charger", "pro"} & set(lower_brands), "iPhone Charger or PRO is still a brand")
sellable = {c["code"] for c in categories if c["sellable"]}
for b in brands:
    for c in b["shown_first_in"]:
        check(c in sellable, f"brand {b['name']} shown first in unknown/parent category {c}")
for name in ("Apple", "Samsung"):
    b = next(x for x in brands if x["name"] == name)
    check(not {"cases", "screen_protectors"} & set(b["shown_first_in"]), f"{name} still shown first on pouches/protectors")
for m in models:
    check(m["brand"] is None or m["brand"] in brand_by_id, f"model {m['name']}: unknown brand {m['brand']}")
    for c in m["categories"]:
        check(c in sellable, f"model {m['name']}: unknown/parent category {c}")
check(any(m["name"] == "Pro" and m["categories"] == ["earbuds"] for m in models), "model Pro missing")
pm_lower = collections.Counter(v["name"].lower() for v in phone_vals)
check(all(n == 1 for n in pm_lower.values()), "phone model names not unique")
fam_ids = {v["id"] for v in picklists["phone_families"]["values"]}
check(all(v["family"] in fam_ids for v in phone_vals), "phone model with unknown family")
check({v["family"] for v in phone_vals} == fam_ids, "a phone family has no models")
for lst in picklists.values():
    for v in lst["values"]:
        check(not re.match(r"^\d+(\.\d+)? [A-Za-z]+$", v["name"]), f"spaced unit left: {v['name']}")
    names = collections.Counter(v["name"].lower() for v in lst["values"])
    check(all(n == 1 for n in names.values()), f"duplicate value in {lst['label']}")
# R2 continuity: the generators must offer exactly v1's 127 lines, in the new unit style
v1_lines = sorted(re.sub(r"(\d) m$", r"\1m", g["name"]) for g in v1["generated_lines"])
v11_lines = sorted(n for g in generators for n in generated_shapes(g))
check(v1_lines == v11_lines, f"generators differ from v1 lines: {set(v1_lines) ^ set(v11_lines)}")
check(seed["counts"]["generated_shapes_on_demand"] == 127, "generated shapes != 127")
check(seed["rules"]["product_word_denylist"] == v1["rules"]["product_word_denylist"], "denylist changed")
check(len(sellable) == 28 and seed["counts"]["parent_categories"] == 6, "category counts are not 28 + 6")
check(len([c for c in categories if c["parent"] is None]) == 19, "top level is not 19 tiles")
if errors:
    print("VALIDATION FAILED:")
    for e in errors:
        print(" -", e)
    sys.exit(1)

with open(OUT_JSON, "w", encoding="utf-8") as fh:
    json.dump(seed, fh, ensure_ascii=False, indent=2)
    fh.write("\n")


# ------------------------------------------------------------------ workbook (stdlib xlsx writer)
def col(i):
    s, i = "", i + 1
    while i:
        i, r = divmod(i - 1, 26)
        s = chr(65 + r) + s
    return s


# style ids: 0 normal, 1 header, 2 title, 3 wrapped, 4 note (italic grey), 5 number, 6 bold
def cell_xml(ref, cell):
    if cell is None:
        return ""
    if not isinstance(cell, dict):
        cell = {"v": cell}
    s = cell.get("s", 3)
    if "f" in cell:
        return f'<c r="{ref}" s="{cell.get("s", 5)}"><f>{escape(cell["f"])}</f><v>{cell["v"]}</v></c>'
    v = cell["v"]
    if isinstance(v, bool):
        v = "Yes" if v else "No"
    if isinstance(v, (int, float)):
        return f'<c r="{ref}" s="{cell.get("s", 5)}"><v>{v}</v></c>'
    return f'<c r="{ref}" s="{s}" t="inlineStr"><is><t xml:space="preserve">{escape(str(v))}</t></is></c>'


class Sheet:
    def __init__(self, name, widths, table=True):
        self.name, self.widths, self.table, self.rows = name, widths, table, []

    def xml(self, selected):
        out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
               '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
               'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">']
        sel = ' tabSelected="1"' if selected else ""
        if self.table:
            out.append(f'<sheetViews><sheetView workbookViewId="0"{sel}><pane ySplit="1" topLeftCell="A2" '
                       'activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
                       '</sheetView></sheetViews>')
        else:
            out.append(f'<sheetViews><sheetView workbookViewId="0"{sel}/></sheetViews>')
        out.append('<sheetFormatPr defaultRowHeight="15"/>')
        out.append("<cols>" + "".join(f'<col min="{i + 1}" max="{i + 1}" width="{w}" customWidth="1"/>'
                                      for i, w in enumerate(self.widths)) + "</cols>")
        out.append("<sheetData>")
        for r, row in enumerate(self.rows, start=1):
            out.append(f'<row r="{r}">' + "".join(cell_xml(f"{col(c)}{r}", v) for c, v in enumerate(row)) + "</row>")
        out.append("</sheetData>")
        if self.table and len(self.rows) > 1:
            out.append(f'<autoFilter ref="{self.ref()}"/>')
        out.append('<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>')
        out.append("</worksheet>")
        return "".join(out)

    def ref(self):
        return f"A1:{col(len(self.rows[0]) - 1)}{len(self.rows)}"


def header(names):
    return [{"v": n, "s": 1} for n in names]


def labels(c, tokens):
    names = {f["key"]: f["label"] for f in c["facets"]}
    return ", ".join("[category word]" if t == W else names[t] for t in tokens)


EVIDENCE = {"ng_sources": "Seen in Nigerian sources", "owner_supplied": "Supplied by the shop owner",
            "to_confirm": "To confirm on the shelf walk"}

sh_cat = Sheet("Categories", [24, 30, 22, 12, 20, 44, 52, 46, 34])
sh_cat.rows.append(header(["Code", "Category", "Parent", "Sellable", "Category word", "Facets, in drill order",
                           "Name order", "Example printed name", "Search words"]))
for c in categories:
    if c["sellable"]:
        sh_cat.rows.append([c["code"], c["name"], c["parent"] or "", "Yes", c["word"], labels(c, c["drill_order"]),
                            labels(c, c["name_order"]), c["example"]["prints"], ", ".join(c.get("aliases", []))])
    else:
        sh_cat.rows.append([c["code"], c["name"], "", "No (parent)", "", "", "", "", ""])

sh_pl = Sheet("Pick-lists", [24, 40, 22, 26, 40])
sh_pl.rows.append(header(["List", "ID", "Value", "Search words", "Origin"]))
for key, lst in picklists.items():
    if key == "phone_models":
        continue
    for v in lst["values"]:
        sh_pl.rows.append([key, v["id"], v["name"], ", ".join(v.get("aliases", [])), lst["origin"]])

sh_ph = Sheet("Phone models", [22, 34, 44, 18])
sh_ph.rows.append(header(["Family", "Model", "ID", "Origin"]))
fam_name = {v["id"]: v["name"] for v in picklists["phone_families"]["values"]}
for v in phone_vals:
    sh_ph.rows.append([fam_name[v["family"]], v["name"], v["id"], "assistant_draft"])

sh_br = Sheet("Brands", [22, 18, 18, 60, 28, 12, 52])
sh_br.rows.append(header(["ID", "Brand", "Also searched as", "Shown first in", "Evidence", "Status", "Note"]))
for b in brands:
    sh_br.rows.append([b["id"], b["name"], ", ".join(b["aliases"]), ", ".join(b["shown_first_in"]),
                       EVIDENCE[b["provenance"]], b["status"], b.get("note", "")])

sh_mo = Sheet("Models", [34, 14, 20, 32, 70])
sh_mo.rows.append(header(["ID", "Brand", "Model or line", "Categories", "Note"]))
for m in models:
    sh_mo.rows.append([m["id"], brand_by_id[m["brand"]]["name"] if m["brand"] else "No brand", m["name"],
                       ", ".join(m["categories"]), m["note"]])

PL_RANGE = "'Pick-lists'!$A$2:$A$2000"
gen_rows = {
    "cables": ("Connector pair x Length", "Length", "", f'COUNTIF({PL_RANGE},"connector_pairs")*(COUNTIF({PL_RANGE},"cable_lengths")+1)'),
    "charger_head": ("Wattage", "", "", f'COUNTIF({PL_RANGE},"wattages")'),
    "complete_charger": ("Connector pair x Wattage", "", "2 pairs: 3-in-1; 3.5mm AUX",
                         f'(COUNTIF({PL_RANGE},"connector_pairs")-2)*COUNTIF({PL_RANGE},"wattages")'),
    "car_chargers": ("Wattage", "", "", f'COUNTIF({PL_RANGE},"wattages")'),
    "power_banks": ("Capacity", "", "", f'COUNTIF({PL_RANGE},"capacities_mah")'),
}
sh_ge = Sheet("Generators", [20, 28, 16, 28, 10])
sh_ge.rows.append(header(["Category", "Combines", "Blank allowed", "Excludes", "Shapes"]))
for g in generators:
    combines, blank, excl, formula = gen_rows[g["category"]]
    sh_ge.rows.append([g["category"], combines, blank, excl, {"f": formula, "v": g["shapes"]}])

sh_ru = Sheet("Rules", [40, 100])
sh_ru.rows.append(header(["Rule", "Setting"]))
for k, v in rules.items():
    shown = ", ".join(v) if isinstance(v, list) else ("True" if v is True else str(v))
    sh_ru.rows.append([k.replace("_", " "), shown])

sh_ch = Sheet("Changes", [16, 60, 90])
sh_ch.rows.append(header(["Ref", "Change from v1", "Detail"]))
for r, c, d in changelog:
    sh_ch.rows.append([r, c, d])

counts_rows = [
    ("Categories, sellable and parent", "COUNTA(Categories!A2:A1000)", seed["counts"]["categories"]),
    ("Sellable categories", 'COUNTIF(Categories!D2:D1000,"Yes")', seed["counts"]["sellable_categories"]),
    ("Parent categories", 'COUNTIF(Categories!D2:D1000,"No (parent)")', seed["counts"]["parent_categories"]),
    ("Brands (No brand is added on top)", "COUNTA(Brands!B2:B1000)", seed["counts"]["brands"]),
    ("Brands to confirm on the shelf walk", 'COUNTIF(Brands!E2:E1000,"To confirm on the shelf walk")',
     sum(b["provenance"] == "to_confirm" for b in brands)),
    ("Models and product lines", "COUNTA(Models!C2:C1000)", seed["counts"]["models"]),
    ("Phone families", f'COUNTIF({PL_RANGE},"phone_families")', seed["counts"]["phone_families"]),
    ("Phone models (first draft)", "COUNTA('Phone models'!B2:B2000)", seed["counts"]["phone_models"]),
    ("Generated shapes, built on demand", "SUM(Generators!E2:E100)", seed["counts"]["generated_shapes_on_demand"]),
]
sh_rd = Sheet("README", [70, 14], table=False)
sh_rd.rows.append([{"v": "HAQ Retail OS: starter catalogue v1.1", "s": 2}])
sh_rd.rows.append([{"v": "Draft for the shop owner to re-confirm. Built on 5 November 2026 from v1 (locked 2 November "
                         "2026) and the decisions recorded in docs/decisions.md.", "s": 4}])
sh_rd.rows.append([{"v": "Source of truth: starter-catalogue-v1.1.json. This workbook and Appendix C are generated from "
                         "it by tools/catalogue/build_catalogue_v11.py; change the seed through that script, not this file.",
                    "s": 4}])
sh_rd.rows.append([])
sh_rd.rows.append(header(["Item", "Count"]))
for label, f, v in counts_rows:
    sh_rd.rows.append([label, {"f": f, "v": v}])
sh_rd.rows.append([])
sh_rd.rows.append([{"v": "How to review", "s": 6}])
for line in [
    "1. Changes: what moved from v1 and why. Each row names the review item or answer behind it.",
    "2. Categories: check each category word and example printed name reads the way the shop says it.",
    "3. Phone models: a first draft; strike models the shop never sees and add missing ones.",
    "4. Brands and Models: confirm the brands marked \"To confirm on the shelf walk\" and the short codes.",
]:
    sh_rd.rows.append([line])
sh_rd.rows.append([])
sh_rd.rows.append([{"v": "Still open", "s": 6}])
for line in still_open:
    sh_rd.rows.append([line])

sheets = [sh_rd, sh_ch, sh_cat, sh_pl, sh_ph, sh_br, sh_mo, sh_ge, sh_ru]
STYLES = (
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    '<fonts count="4">'
    '<font><sz val="10"/><name val="Arial"/><family val="2"/></font>'
    '<font><b/><sz val="10"/><name val="Arial"/><family val="2"/></font>'
    '<font><b/><sz val="14"/><name val="Arial"/><family val="2"/></font>'
    '<font><i/><sz val="10"/><color rgb="FF595959"/><name val="Arial"/><family val="2"/></font>'
    '</fonts>'
    '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    '<fill><patternFill patternType="solid"><fgColor rgb="FFF0EFEC"/><bgColor indexed="64"/></patternFill></fill></fills>'
    '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
    '<border><left/><right/><top/><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border></borders>'
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    '<cellXfs count="7">'
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" '
    'applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
    '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
    '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
    '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>'
    '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
    '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>')


def q(name):
    return "'" + name.replace("'", "''") + "'"


defined = "".join(
    f'<definedName name="_xlnm._FilterDatabase" localSheetId="{i}" hidden="1">{escape(q(s.name))}!'
    f'${s.ref().split(":")[0][0]}$1:${re.sub(r"[0-9]", "", s.ref().split(":")[1])}${len(s.rows)}</definedName>'
    for i, s in enumerate(sheets) if s.table and len(s.rows) > 1)
workbook = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            '<bookViews><workbookView activeTab="0"/></bookViews><sheets>'
            + "".join(f'<sheet name="{escape(s.name)}" sheetId="{i + 1}" r:id="rId{i + 1}"/>' for i, s in enumerate(sheets))
            + "</sheets>" + (f"<definedNames>{defined}</definedNames>" if defined else "")
            + '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>')
wb_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
           + "".join(f'<Relationship Id="rId{i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                     f'relationships/worksheet" Target="worksheets/sheet{i + 1}.xml"/>' for i in range(len(sheets)))
           + f'<Relationship Id="rId{len(sheets) + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
             'relationships/styles" Target="styles.xml"/></Relationships>')
content_types = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                 '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                 '<Default Extension="xml" ContentType="application/xml"/>'
                 '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.'
                 'spreadsheetml.sheet.main+xml"/>'
                 + "".join(f'<Override PartName="/xl/worksheets/sheet{i + 1}.xml" ContentType="application/'
                           'vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' for i in range(len(sheets)))
                 + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.'
                   'spreadsheetml.styles+xml"/>'
                 '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-'
                 'properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-'
                 'officedocument.extended-properties+xml"/></Types>')
root_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
             'officeDocument" Target="xl/workbook.xml"/>'
             '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/'
             'core-properties" Target="docProps/core.xml"/>'
             '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
             'extended-properties" Target="docProps/app.xml"/></Relationships>')
core = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        '<dc:title>HAQ Retail OS starter catalogue v1.1</dc:title><dc:creator>HAQ Technology</dc:creator>'
        f'<dcterms:created xsi:type="dcterms:W3CDTF">{TODAY}T00:00:00Z</dcterms:created>'
        f'<dcterms:modified xsi:type="dcterms:W3CDTF">{TODAY}T00:00:00Z</dcterms:modified></cp:coreProperties>')
app = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
       '<Application>HAQ catalogue build</Application></Properties>')
def put(z, name, data):
    """Fixed timestamp and no compression, so the workbook is byte-identical on every machine (CI compares it)."""
    info = zipfile.ZipInfo(name, date_time=(2026, 11, 5, 0, 0, 0))
    info.compress_type = zipfile.ZIP_STORED
    info.create_system = 3
    info.external_attr = 0o644 << 16
    z.writestr(info, data.encode("utf-8"))


with zipfile.ZipFile(OUT_XLSX, "w") as z:
    put(z, "[Content_Types].xml", content_types)
    put(z, "_rels/.rels", root_rels)
    put(z, "docProps/core.xml", core)
    put(z, "docProps/app.xml", app)
    put(z, "xl/workbook.xml", workbook)
    put(z, "xl/_rels/workbook.xml.rels", wb_rels)
    put(z, "xl/styles.xml", STYLES)
    for i, s in enumerate(sheets):
        put(z, f"xl/worksheets/sheet{i + 1}.xml", s.xml(selected=(i == 0)))


# ------------------------------------------------------------------ Appendix C (Markdown)
def md_row(cells):
    return "| " + " | ".join(str(c).replace("|", "\\|") for c in cells) + " |"


md = []
md.append("# Appendix C. Starter catalogue v1.1")
md.append("")
md.append("Draft for the shop owner to re-confirm, built on 5 November 2026 from v1 (locked 2 November 2026) and the "
          "decisions in docs/decisions.md. Generated from `starter-catalogue-v1.1.json` by "
          "`tools/catalogue/build_catalogue_v11.py`: if this appendix and the seed differ, the seed wins, so regenerate "
          "rather than edit.")
md.append("")
md.append("## C1. What is in it")
md.append("")
md.append(md_row(["Item", "Count"]))
md.append(md_row(["---", "---"]))
for label, _, v in counts_rows:
    md.append(md_row([label, v]))
md.append(md_row(["Pick-lists", seed["counts"]["picklists"]]))
md.append("")
md.append("## C2. Rules locked with it")
md.append("")
for k, v in rules.items():
    shown = ", ".join(v) if isinstance(v, list) else ("yes" if v is True else str(v))
    md.append(f"- **{k.replace('_', ' ').capitalize()}:** {shown}")
md.append("")
md.append("## C3. Category templates")
md.append("")
md.append("Printed names follow each category's name order; blank parts are skipped and No brand never prints. "
          "Drill order is the order of the tap-through tiles (proposed until the cashier timing test at G1).")
md.append("")
md.append(md_row(["Category", "Parent", "Category word", "Facets, in drill order", "Name order", "Example printed name"]))
md.append(md_row(["---"] * 6))
for c in categories:
    if c["sellable"]:
        md.append(md_row([c["name"], cat_by_code[c["parent"]]["name"] if c["parent"] else "", c["word"],
                          labels(c, c["drill_order"]), labels(c, c["name_order"]), c["example"]["prints"]]))
    else:
        md.append(md_row([c["name"], "", "(parent, not sellable)", "", "", ""]))
md.append("")
md.append("## C4. Pick-lists")
md.append("")
md.append(md_row(["List", "Values", "Origin"]))
md.append(md_row(["---", "---", "---"]))
for key, lst in picklists.items():
    if key == "phone_models":
        md.append(md_row([lst["label"], f"{len(lst['values'])} models in 19 families (C5)", lst["origin"]]))
        continue
    vals = []
    for v in lst["values"]:
        vals.append(v["name"] + (f" (also: {', '.join(v['aliases'])})" if v.get("aliases") else ""))
    md.append(md_row([lst["label"] + f" ({len(lst['values'])})", ", ".join(vals), lst["origin"]]))
md.append("")
md.append("## C5. Phone models (first draft)")
md.append("")
md.append(picklists["phone_models"]["note"])
md.append("")
for fam, names in PHONES.items():
    md.append(f"- **{fam}** ({len(names)}): {', '.join(names)}")
md.append("")
md.append("## C6. Brands")
md.append("")
md.append(f"{len(brands)} brands plus \"No brand\". Each category shows its own brands first, then the rest.")
md.append("")
md.append(md_row(["Brand", "Also searched as", "Shown first in", "Evidence", "Note"]))
md.append(md_row(["---"] * 5))
for b in brands:
    md.append(md_row([b["name"], ", ".join(b["aliases"]), ", ".join(b["shown_first_in"]) or "(none yet)",
                      EVIDENCE[b["provenance"]], b.get("note", "")]))
md.append("")
md.append("## C7. Models and product lines")
md.append("")
md.append(md_row(["Brand", "Model or line", "Categories", "Note"]))
md.append(md_row(["---"] * 4))
for m in models:
    md.append(md_row([brand_by_id[m["brand"]]["name"] if m["brand"] else "No brand", m["name"],
                      ", ".join(m["categories"]), m["note"]]))
md.append("")
md.append("## C8. Generated shapes, built on demand")
md.append("")
md.append("The counter offers these unbranded shapes in search and on the tiles; an item is created the first time "
          "one sells, so impossible combinations never reach a report and setup needs no prune review.")
md.append("")
md.append(md_row(["Category", "Combines", "Blank allowed", "Excludes", "Shapes"]))
md.append(md_row(["---"] * 5))
for g in generators:
    combines, blank, excl, _ = gen_rows[g["category"]]
    md.append(md_row([cat_by_code[g["category"]]["name"], combines.replace(" x ", " × "), blank or "", excl or "",
                      g["shapes"]]))
md.append(md_row(["Total", "", "", "", seed["counts"]["generated_shapes_on_demand"]]))
md.append("")
md.append("## C9. Changes from v1")
md.append("")
md.append(md_row(["Ref", "Change", "Detail"]))
md.append(md_row(["---"] * 3))
for r, c, d in changelog:
    md.append(md_row([r, c, d]))
md.append("")
md.append("## C10. Still open")
md.append("")
for line in still_open:
    md.append(f"- {line}")
md.append("")
with open(OUT_MD, "w", encoding="utf-8") as fh:
    fh.write("\n".join(md))

print("OK: v1.1 built and validated")
print(json.dumps(seed["counts"], indent=1))
