# Appendix C. Starter catalogue v1.1

Draft for the shop owner to re-confirm, built on 5 November 2026 from v1 (locked 2 November 2026) and the decisions in docs/decisions.md. Generated from `starter-catalogue-v1.1.json` by `tools/catalogue/build_catalogue_v11.py`: if this appendix and the seed differ, the seed wins, so regenerate rather than edit.

## C1. What is in it

| Item | Count |
| --- | --- |
| Categories, sellable and parent | 34 |
| Sellable categories | 28 |
| Parent categories | 6 |
| Brands (No brand is added on top) | 73 |
| Brands to confirm on the shelf walk | 16 |
| Models and product lines | 29 |
| Phone families | 19 |
| Phone models (first draft) | 466 |
| Generated shapes, built on demand | 127 |
| Pick-lists | 15 |

## C2. Rules locked with it

- **Category required:** yes
- **All other facets optional:** yes
- **Brand unique case insensitive:** yes
- **New brand:** Cashier may type any brand. Receipt prints it as typed. It stays on that item only until a manager or owner adds it to the pick-list from the review queue. The brand box suggests typed brands still waiting for approval.
- **Who adds brand to list:** manager, owner
- **No category fits:** Picker offers 'Not in the list'. Cashier types the product word (minimum 3 characters). It prints as typed as the category word. Item is pending review. Only the owner creates categories.
- **Product word denylist:** others, other, misc, miscellaneous, item, items, test, unlisted, general, stuff, product
- **Who creates category:** owner
- **Cable length optional:** yes
- **Cable rated power optional:** yes
- **Brand not multiplied into generated lines:** yes
- **Generated lines built on demand:** yes
- **Every sellable category has a word:** yes
- **New phone model:** Cashier may type a phone model that is not in the list. It prints as typed, stays on that item, and joins the list when a manager or the owner approves it.
- **Who adds phone model to list:** manager, owner
- **Made by defaults to no brand:** cases, screen_protectors, batteries
- **Units written without space:** yes

## C3. Category templates

Printed names follow each category's name order; blank parts are skipped and No brand never prints. Drill order is the order of the tap-through tiles (proposed until speed data from the shadow pilot confirms it).

| Category | Parent | Category word | Facets, in drill order | Name order | Example printed name |
| --- | --- | --- | --- | --- | --- |
| Cables |  | Cable | Connector pair, Length, Brand, Rated power | Brand, Connector pair, [category word], Length, Rated power | Anker Type-C to Lightning Cable 1m |
| Wall chargers |  | (parent, not sellable) |  |  |  |
| Charger head | Wall chargers | Charger Head | Wattage, Brand | Brand, [category word], Wattage | Otek Charger Head 20W |
| Complete charger | Wall chargers | Complete Charger | Connector pair, Wattage, Brand | Brand, Connector pair, [category word], Wattage | Oraimo Type-C to Lightning Complete Charger 20W |
| Car chargers |  | Car Charger | Wattage, Brand, Model | Brand, Model, [category word], Wattage | KLY Car Charger 12W |
| Wireless chargers |  | Wireless Charger | Brand, Model, Wattage | Brand, Model, [category word], Wattage | Baseus Wireless Charger 20W |
| Power banks |  | Power Bank | Brand, Capacity, Model | Brand, Model, [category word], Capacity | Oraimo Traveler 4 Power Bank 20000mAh |
| Earbuds and earpods |  | Earbuds | Brand, Model | Brand, Model, [category word] | Oraimo FreePods 3C Earbuds |
| Wired earphones and handsfree |  | Earphones | Brand, Model, Plug type | Brand, Model, [category word], Plug type | Q10 Earphones 3.5mm |
| Neckband earphones |  | Neckband Earphones | Brand, Model | Brand, Model, [category word] | Oraimo Neckband Earphones |
| Headphones |  | Headphones | Brand, Model | Brand, Model, [category word] | JBL Headphones |
| Bluetooth speakers |  | Bluetooth Speaker | Brand, Model | Brand, Model, [category word] | Zealot S32 Bluetooth Speaker |
| Smartwatches and bands |  | (parent, not sellable) |  |  |  |
| Smartwatch | Smartwatches and bands | Smartwatch | Brand, Model | Brand, Model, [category word] | Infinix XWatch 3 WE Smartwatch |
| Band | Smartwatches and bands | Band | Brand, Model | Brand, Model, [category word] | Huawei Band |
| Phone pouches and covers |  | Pouch | Phone model, Pouch type, Made by | Made by, Phone model, Pouch type, [category word] | iPhone 11 Silicone Pouch |
| Screen protectors |  | Screen Protector | Phone model, Protector type, Made by | Made by, Phone model, Protector type, [category word] | Tecno Spark 20 Tempered Glass Screen Protector |
| Phone holders and stands |  | Phone Holder | Kind, Brand | Brand, Kind, [category word] | Baseus Car Phone Holder |
| Memory cards and flash drives |  | (parent, not sellable) |  |  |  |
| Memory card | Memory cards and flash drives | Memory Card | Capacity, Brand | Brand, [category word], Capacity | Sony Memory Card 64GB |
| Flash drive | Memory cards and flash drives | Flash Drive | Capacity, Brand | Brand, [category word], Capacity | Flash Drive 32GB |
| Replacement batteries |  | Battery | Phone model, Made by | Made by, Phone model, [category word] | Tecno Spark 5 Battery |
| Selfie sticks, ring lights, tripods |  | (parent, not sellable) |  |  |  |
| Selfie stick | Selfie sticks, ring lights, tripods | Selfie Stick | Brand, Size | Brand, [category word], Size | Selfie Stick |
| Ring light | Selfie sticks, ring lights, tripods | Ring Light | Size, Brand | Brand, [category word], Size | Ring Light 10 inch |
| Tripod | Selfie sticks, ring lights, tripods | Tripod | Size, Brand | Brand, [category word], Size | Tripod |
| Adapters (OTG, AUX, splitters) |  | (parent, not sellable) |  |  |  |
| OTG adapter | Adapters (OTG, AUX, splitters) | OTG Adapter | Connector | [category word], Connector | OTG Adapter Type-C |
| AUX adapter | Adapters (OTG, AUX, splitters) | AUX Adapter | Connector | [category word], Connector | AUX Adapter Lightning |
| Splitter | Adapters (OTG, AUX, splitters) | Splitter | Connector | [category word], Connector | Splitter 3.5mm |
| Computer accessories (mouse, keyboard, hubs) |  | (parent, not sellable) |  |  |  |
| Mouse | Computer accessories (mouse, keyboard, hubs) | Mouse | Connectivity, Brand, Model | Brand, Connectivity, [category word], Model | Wireless Mouse |
| Keyboard | Computer accessories (mouse, keyboard, hubs) | Keyboard | Connectivity, Brand, Model | Brand, Connectivity, [category word], Model | Wired Keyboard |
| USB hub | Computer accessories (mouse, keyboard, hubs) | USB Hub | Brand, Model | Brand, [category word], Model | USB Hub |

## C4. Pick-lists

| List | Values | Origin |
| --- | --- | --- |
| Connector pair (7) | Type-C to Type-C, USB-A to Type-C, USB-A to Lightning (also: iphone charger, iphone), Type-C to Lightning (also: iphone charger, iphone), USB-A to Micro-USB, 3-in-1, 3.5mm AUX | owner |
| Cable length (5) | 0.25m, 1m, 1.5m, 2m, 3m | owner |
| Cable rated power (3) | 20W, 60W, 100W | assistant_suggestion |
| Wattage (10) | 12W, 18W, 20W, 25W, 33W, 35W, 45W, 60W, 65W, 100W | owner |
| Capacity (mAh) (15) | 5000mAh, 10000mAh, 12500mAh, 15000mAh, 20000mAh, 22500mAh, 27000mAh, 30000mAh, 33000mAh, 40000mAh, 44000mAh, 50000mAh, 55000mAh, 60000mAh, 80000mAh | owner |
| Colour (12) | Black, White, Blue, Red, Pink, Green, Grey (also: gray), Purple, Gold, Silver, Orange, Yellow | owner |
| Pouch type (6) | Silicone, Transparent, Leather, Rugged, Wallet Flip, Magnetic | owner: Silicone, Transparent; assistant_suggestion: the rest |
| Plug type (3) | 3.5mm, Type-C, Lightning | assistant_suggestion |
| Protector type (3) | Tempered Glass, Hydrogel, Privacy | assistant_suggestion |
| Holder kind (4) | Car, Desk, Ring, Magnetic | assistant_suggestion |
| Capacity (GB) (7) | 8GB, 16GB, 32GB, 64GB, 128GB, 256GB, 512GB | assistant_suggestion |
| Adapter connector (5) | Type-C, USB-A, Lightning, Micro-USB, 3.5mm | assistant_suggestion (v1 inline values) |
| Connectivity (3) | Wired, Wireless, Bluetooth | assistant_suggestion |
| Phone family (19) | iPhone, Samsung Galaxy A, Samsung Galaxy S, Samsung Galaxy M, Tecno Spark, Tecno Camon, Tecno Pova, Tecno Phantom, Infinix Hot, Infinix Note, Infinix Smart, Infinix Zero and GT, itel A, P and S, Xiaomi Redmi, Poco, Oppo, Realme, Huawei and Honor, Nokia and HMD | owner |
| Phone model | 466 models in 19 families (C5) | assistant_draft |

## C5. Phone models (first draft)

Public model names drafted from memory as of mid-2025. Verify against the shelf and supplier lists. A cashier can type a model that is missing; it prints as typed and joins the list once a manager or the owner approves it.

- **iPhone** (42): iPhone 6, iPhone 6 Plus, iPhone 6s, iPhone 6s Plus, iPhone 7, iPhone 7 Plus, iPhone 8, iPhone 8 Plus, iPhone X, iPhone XR, iPhone XS, iPhone XS Max, iPhone SE 2020, iPhone 11, iPhone 11 Pro, iPhone 11 Pro Max, iPhone 12 mini, iPhone 12, iPhone 12 Pro, iPhone 12 Pro Max, iPhone 13 mini, iPhone 13, iPhone 13 Pro, iPhone 13 Pro Max, iPhone SE 2022, iPhone 14, iPhone 14 Plus, iPhone 14 Pro, iPhone 14 Pro Max, iPhone 15, iPhone 15 Plus, iPhone 15 Pro, iPhone 15 Pro Max, iPhone 16, iPhone 16 Plus, iPhone 16 Pro, iPhone 16 Pro Max, iPhone 16e, iPhone 17, iPhone Air, iPhone 17 Pro, iPhone 17 Pro Max
- **Samsung Galaxy A** (45): Samsung Galaxy A02s, Samsung Galaxy A03, Samsung Galaxy A03s, Samsung Galaxy A04, Samsung Galaxy A04e, Samsung Galaxy A04s, Samsung Galaxy A05, Samsung Galaxy A05s, Samsung Galaxy A06, Samsung Galaxy A10, Samsung Galaxy A10s, Samsung Galaxy A11, Samsung Galaxy A12, Samsung Galaxy A13, Samsung Galaxy A14, Samsung Galaxy A15, Samsung Galaxy A16, Samsung Galaxy A20, Samsung Galaxy A20s, Samsung Galaxy A21s, Samsung Galaxy A22, Samsung Galaxy A23, Samsung Galaxy A24, Samsung Galaxy A25, Samsung Galaxy A26, Samsung Galaxy A30, Samsung Galaxy A30s, Samsung Galaxy A31, Samsung Galaxy A32, Samsung Galaxy A33, Samsung Galaxy A34, Samsung Galaxy A35, Samsung Galaxy A36, Samsung Galaxy A50, Samsung Galaxy A51, Samsung Galaxy A52, Samsung Galaxy A52s, Samsung Galaxy A53, Samsung Galaxy A54, Samsung Galaxy A55, Samsung Galaxy A56, Samsung Galaxy A70, Samsung Galaxy A71, Samsung Galaxy A72, Samsung Galaxy A73
- **Samsung Galaxy S** (31): Samsung Galaxy S8, Samsung Galaxy S8+, Samsung Galaxy S9, Samsung Galaxy S9+, Samsung Galaxy S10e, Samsung Galaxy S10, Samsung Galaxy S10+, Samsung Galaxy S20, Samsung Galaxy S20+, Samsung Galaxy S20 Ultra, Samsung Galaxy S20 FE, Samsung Galaxy S21, Samsung Galaxy S21+, Samsung Galaxy S21 Ultra, Samsung Galaxy S21 FE, Samsung Galaxy S22, Samsung Galaxy S22+, Samsung Galaxy S22 Ultra, Samsung Galaxy S23, Samsung Galaxy S23+, Samsung Galaxy S23 Ultra, Samsung Galaxy S23 FE, Samsung Galaxy S24, Samsung Galaxy S24+, Samsung Galaxy S24 Ultra, Samsung Galaxy S24 FE, Samsung Galaxy S25, Samsung Galaxy S25+, Samsung Galaxy S25 Ultra, Samsung Galaxy S25 Edge, Samsung Galaxy S25 FE
- **Samsung Galaxy M** (17): Samsung Galaxy M12, Samsung Galaxy M13, Samsung Galaxy M14, Samsung Galaxy M15, Samsung Galaxy M21, Samsung Galaxy M22, Samsung Galaxy M23, Samsung Galaxy M31, Samsung Galaxy M32, Samsung Galaxy M33, Samsung Galaxy M34, Samsung Galaxy M35, Samsung Galaxy M51, Samsung Galaxy M52, Samsung Galaxy M53, Samsung Galaxy M54, Samsung Galaxy M55
- **Tecno Spark** (27): Tecno Spark 5, Tecno Spark 5 Pro, Tecno Spark 6, Tecno Spark 6 Go, Tecno Spark 7, Tecno Spark 7P, Tecno Spark 7 Pro, Tecno Spark 8, Tecno Spark 8C, Tecno Spark 8P, Tecno Spark 8 Pro, Tecno Spark 9, Tecno Spark 9 Pro, Tecno Spark 9T, Tecno Spark 10, Tecno Spark 10C, Tecno Spark 10 Pro, Tecno Spark 20, Tecno Spark 20C, Tecno Spark 20 Pro, Tecno Spark 20 Pro+, Tecno Spark 30, Tecno Spark 30C, Tecno Spark 30 Pro, Tecno Spark Go 2022, Tecno Spark Go 2023, Tecno Spark Go 2024
- **Tecno Camon** (24): Tecno Camon 12, Tecno Camon 12 Pro, Tecno Camon 15, Tecno Camon 15 Pro, Tecno Camon 16, Tecno Camon 16 Premier, Tecno Camon 17, Tecno Camon 17P, Tecno Camon 17 Pro, Tecno Camon 18, Tecno Camon 18P, Tecno Camon 18 Premier, Tecno Camon 19, Tecno Camon 19 Neo, Tecno Camon 19 Pro, Tecno Camon 20, Tecno Camon 20 Pro, Tecno Camon 20 Premier, Tecno Camon 30, Tecno Camon 30 Pro, Tecno Camon 30 Premier, Tecno Camon 40, Tecno Camon 40 Pro, Tecno Camon 40 Premier
- **Tecno Pova** (12): Tecno Pova, Tecno Pova 2, Tecno Pova 3, Tecno Pova 4, Tecno Pova 4 Pro, Tecno Pova 5, Tecno Pova 5 Pro, Tecno Pova 6 Pro, Tecno Pova 6 Neo, Tecno Pova Neo, Tecno Pova Neo 2, Tecno Pova Neo 3
- **Tecno Phantom** (8): Tecno Phantom 9, Tecno Phantom X, Tecno Phantom X2, Tecno Phantom X2 Pro, Tecno Phantom V Fold, Tecno Phantom V Flip, Tecno Phantom V Fold2, Tecno Phantom V Flip2
- **Infinix Hot** (27): Infinix Hot 8, Infinix Hot 9, Infinix Hot 9 Play, Infinix Hot 10, Infinix Hot 10 Play, Infinix Hot 10i, Infinix Hot 10S, Infinix Hot 11, Infinix Hot 11 Play, Infinix Hot 11S, Infinix Hot 12, Infinix Hot 12 Play, Infinix Hot 12i, Infinix Hot 20, Infinix Hot 20i, Infinix Hot 20 Play, Infinix Hot 20S, Infinix Hot 30, Infinix Hot 30i, Infinix Hot 30 Play, Infinix Hot 40, Infinix Hot 40i, Infinix Hot 40 Pro, Infinix Hot 50, Infinix Hot 50i, Infinix Hot 50 Pro, Infinix Hot 50 Pro+
- **Infinix Note** (22): Infinix Note 7, Infinix Note 8, Infinix Note 8i, Infinix Note 10, Infinix Note 10 Pro, Infinix Note 11, Infinix Note 11 Pro, Infinix Note 11S, Infinix Note 12, Infinix Note 12 Pro, Infinix Note 12 G96, Infinix Note 12 VIP, Infinix Note 30, Infinix Note 30 Pro, Infinix Note 30 VIP, Infinix Note 40, Infinix Note 40 Pro, Infinix Note 40 Pro+, Infinix Note 40S, Infinix Note 50, Infinix Note 50 Pro, Infinix Note 50 Pro+
- **Infinix Smart** (13): Infinix Smart 4, Infinix Smart 5, Infinix Smart 6, Infinix Smart 6 HD, Infinix Smart 6 Plus, Infinix Smart 7, Infinix Smart 7 HD, Infinix Smart 8, Infinix Smart 8 HD, Infinix Smart 8 Plus, Infinix Smart 8 Pro, Infinix Smart 9, Infinix Smart 9 HD
- **Infinix Zero and GT** (10): Infinix Zero 8, Infinix Zero X, Infinix Zero X Pro, Infinix Zero 20, Infinix Zero Ultra, Infinix Zero 30, Infinix Zero 40, Infinix Zero Flip, Infinix GT 10 Pro, Infinix GT 20 Pro
- **itel A, P and S** (23): itel A05s, itel A18, itel A25, itel A26, itel A27, itel A48, itel A49, itel A50, itel A56, itel A58, itel A60, itel A60s, itel A70, itel P17, itel P36, itel P37, itel P38, itel P40, itel P55, itel S16, itel S18, itel S23, itel S24
- **Xiaomi Redmi** (34): Redmi 9, Redmi 9A, Redmi 9C, Redmi 9T, Redmi 10, Redmi 10A, Redmi 10C, Redmi 12, Redmi 12C, Redmi 13, Redmi 13C, Redmi 14C, Redmi A1, Redmi A2, Redmi A3, Redmi Note 8, Redmi Note 8 Pro, Redmi Note 9, Redmi Note 9 Pro, Redmi Note 9S, Redmi Note 10, Redmi Note 10 Pro, Redmi Note 10S, Redmi Note 11, Redmi Note 11 Pro, Redmi Note 11S, Redmi Note 12, Redmi Note 12 Pro, Redmi Note 13, Redmi Note 13 Pro, Redmi Note 13 Pro+, Redmi Note 14, Redmi Note 14 Pro, Redmi Note 14 Pro+
- **Poco** (26): Poco X3 NFC, Poco X3 Pro, Poco X4 Pro, Poco X5, Poco X5 Pro, Poco X6, Poco X6 Pro, Poco X7, Poco X7 Pro, Poco F3, Poco F4, Poco F5, Poco F5 Pro, Poco F6, Poco F6 Pro, Poco F7 Pro, Poco F7 Ultra, Poco M3, Poco M4 Pro, Poco M5, Poco M6 Pro, Poco C40, Poco C51, Poco C61, Poco C65, Poco C75
- **Oppo** (26): Oppo A5s, Oppo A12, Oppo A15, Oppo A16, Oppo A17, Oppo A18, Oppo A38, Oppo A54, Oppo A57, Oppo A58, Oppo A77, Oppo A78, Oppo A79, Oppo A3x, Oppo Reno5, Oppo Reno6, Oppo Reno7, Oppo Reno8, Oppo Reno8 T, Oppo Reno10, Oppo Reno11, Oppo Reno11 F, Oppo Reno12, Oppo Reno12 F, Oppo Reno13, Oppo Reno13 F
- **Realme** (30): Realme C11, Realme C12, Realme C15, Realme C21, Realme C21Y, Realme C25, Realme C30, Realme C31, Realme C33, Realme C35, Realme C51, Realme C53, Realme C55, Realme C61, Realme C63, Realme C65, Realme C67, Realme C75, Realme Note 50, Realme Note 60, Realme 8, Realme 9, Realme 10, Realme 11, Realme 12, Realme 12 Pro, Realme 12 Pro+, Realme 13, Realme 13 Pro, Realme 13 Pro+
- **Huawei and Honor** (24): Huawei Y6p, Huawei Y7a, Huawei Y9a, Huawei Nova 7i, Huawei Nova 9, Huawei Nova 10, Huawei Nova 11, Huawei Nova 12, Huawei P30, Huawei P30 Lite, Huawei P40 Lite, Honor X5, Honor X6, Honor X7, Honor X7a, Honor X7b, Honor X8, Honor X8a, Honor X8b, Honor X9a, Honor X9b, Honor X9c, Honor 90, Honor 200
- **Nokia and HMD** (25): Nokia C1, Nokia C2, Nokia C10, Nokia C12, Nokia C20, Nokia C21, Nokia C21 Plus, Nokia C22, Nokia C30, Nokia C31, Nokia C32, Nokia G10, Nokia G11, Nokia G20, Nokia G21, Nokia G22, Nokia G42, Nokia X10, Nokia X20, HMD Pulse, HMD Pulse Plus, HMD Pulse Pro, HMD Skyline, HMD Fusion, HMD Arc

## C6. Brands

73 brands plus "No brand". Each category shows its own brands first, then the rest.

| Brand | Also searched as | Shown first in | Evidence | Note |
| --- | --- | --- | --- | --- |
| Oraimo |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| New Age |  | power_banks, cables, charger_head, complete_charger | Seen in Nigerian sources |  |
| Romoss |  | power_banks | Seen in Nigerian sources |  |
| Anker |  | power_banks, cables, charger_head, complete_charger | Seen in Nigerian sources |  |
| Baseus |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, cases, screen_protectors | Seen in Nigerian sources |  |
| Ugreen |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| OH Mobility |  | power_banks, cables, charger_head, complete_charger | Seen in Nigerian sources |  |
| Infinix |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| Xiaomi | Redmi | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| Samsung |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | To confirm on the shelf walk |  |
| itel |  | power_banks, cables, charger_head, complete_charger, car_chargers, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| Tecno |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | To confirm on the shelf walk |  |
| Hoco |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | To confirm on the shelf walk |  |
| Remax |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | To confirm on the shelf walk |  |
| Joyroom |  | power_banks, cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | To confirm on the shelf walk |  |
| Borofone |  | power_banks, cables, charger_head, complete_charger | To confirm on the shelf walk |  |
| KLY |  | power_banks, cables, charger_head, complete_charger, car_chargers, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Apple |  | cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| Mcdodo |  | cables, charger_head, complete_charger | To confirm on the shelf walk |  |
| USAMS |  | cables, charger_head, complete_charger | To confirm on the shelf walk |  |
| Sauki |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Shplus |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Amaya |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Mikorean |  | cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Focus |  | cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Poga |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| LCZ |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| GC |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| DataCable |  | cables, charger_head, complete_charger | Supplied by the shop owner | Printed label on the packaging (owner, 5 Nov 2026) |
| Rayrock |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Highmarks |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Datech |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Otek |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| IFY |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Finnary |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Soncjy |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Chupez |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| BK |  | cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Elegant |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Loko |  | cables, charger_head, complete_charger, earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Fil |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| Epcor |  | cables, charger_head, complete_charger | Supplied by the shop owner |  |
| AA |  | car_chargers | Supplied by the shop owner |  |
| Soundcore | Anker Soundcore | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| JBL |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| Realme |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | To confirm on the shelf walk |  |
| Sony |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| Huawei |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers, smartwatch, band | Seen in Nigerian sources |  |
| Havit |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| Jabra |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Seen in Nigerian sources |  |
| QCY |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | To confirm on the shelf walk |  |
| Jamax |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Frezzil |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Bose |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Zealot |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Boombest |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Wuf |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Hapilife |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner |  |
| Baseno |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Kept exactly as written |
| HF |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| AI |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| LP |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| BM |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| DV |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| JZ |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| EX |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| UWO |  | earbuds, wired_earphones, neckband, headphones, bluetooth_speakers | Supplied by the shop owner | Short code; confirm against the real product at the shelf walk (C5) |
| Amazfit |  | smartwatch, band | To confirm on the shelf walk |  |
| Spigen |  | cases, screen_protectors | To confirm on the shelf walk |  |
| Ringke |  | cases, screen_protectors | To confirm on the shelf walk |  |
| UAG |  | cases, screen_protectors | To confirm on the shelf walk |  |
| Nillkin |  | cases, screen_protectors | To confirm on the shelf walk |  |
| Otterbox |  | cases, screen_protectors | To confirm on the shelf walk |  |

## C7. Models and product lines

| Brand | Model or line | Categories | Note |
| --- | --- | --- | --- |
| No brand | Pro 5s | earbuds, wired_earphones | model code, brand unknown (owner) |
| No brand | Q10 | earbuds, wired_earphones | model code, brand unknown (owner) |
| No brand | S195 | earbuds, wired_earphones | model code, brand unknown (owner) |
| No brand | G133 | earbuds, wired_earphones | model code, brand unknown (owner) |
| No brand | VIP | earbuds, wired_earphones | model code, brand unknown (owner) |
| No brand | J7 | car_chargers | model code, brand unknown (owner) |
| No brand | car mp3 s8 | car_chargers | model code, brand unknown (owner) |
| Oraimo | FreePods 3 | earbuds | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | FreePods 3C | earbuds | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | FreePods 4 | earbuds | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | SpaceBuds | earbuds | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Traveler 4 | power_banks | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Traveler Link | power_banks | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Slice Link | power_banks | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | PowerFlow | power_banks | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Toast | power_banks | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | PowerCube | charger_head, complete_charger | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | FastLine | cables | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Undo | cables | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Braid | cables | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | SilkLine | cables | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Candy | cables | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | SpaceBox | bluetooth_speakers | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | BoomPop | headphones | product line from ng.oraimo.com and retailers, not a full model number |
| Oraimo | Watch | smartwatch | product line from ng.oraimo.com and retailers, not a full model number |
| Infinix | XBuds NC | earbuds | accessory line from Infinix launch coverage |
| Infinix | XWatch 3 WE | smartwatch | accessory line from Infinix launch coverage |
| Infinix | XPower 20 GO | power_banks | accessory line from Infinix launch coverage |
| No brand | Pro | earbuds | Product name sold as earpods (owner, 5 Nov 2026); moved from Brands; not the same product as Pro 5s |

## C8. Generated shapes, built on demand

The counter offers these unbranded shapes in search and on the tiles; an item is created the first time one sells, so impossible combinations never reach a report and setup needs no prune review.

| Category | Combines | Blank allowed | Excludes | Shapes |
| --- | --- | --- | --- | --- |
| Cables | Connector pair × Length | Length |  | 42 |
| Charger head | Wattage |  |  | 10 |
| Complete charger | Connector pair × Wattage |  | 2 pairs: 3-in-1; 3.5mm AUX | 50 |
| Car chargers | Wattage |  |  | 10 |
| Power banks | Capacity |  |  | 15 |
| Total |  |  |  | 127 |

## C9. Changes from v1

| Ref | Change | Detail |
| --- | --- | --- |
| C1 | Four word-less categories, plus Smartwatches, became parents with 13 sellable children | Memory card, Flash drive; Selfie stick, Ring light, Tripod; OTG adapter, AUX adapter, Splitter; Mouse, Keyboard, USB hub; Smartwatch, Band. Every sellable category now has a category word, so no receipt line can print blank. The inline kind lists and the kind_or_word token are retired. The top level still shows 19 tiles. |
| C2 | Phone models became a two-level pick-list used by pouches, screen protectors and batteries | 19 families, 466 models drafted from public names; verify at the shelf walk. A missing model can be typed at the counter; it waits for approval like a typed brand. |
| C3 + Q16 | "Made by" replaces Brand on pouches, protectors and batteries, defaulting to No brand | The phone's maker now comes from the phone model. Apple and Samsung are no longer shown first there, because on pouches the owner uses them to mean the phone. |
| C4 + Q17 | "iPhone Charger" left the brand list | It is a description, so "iphone charger" and "iphone" became search words on the two Lightning connector pairs. DataCable stays a brand: it is printed on the packaging. |
| C5 + C6 + F5 | PRO moved from Brands to Models | Pro is an earpods product (No brand, Earbuds), separate from Pro 5s. J7 and car mp3 s8 stay under Car chargers and print as typed. |
| C7 + Q19 | Wireless chargers keep the shared wattage list | The example now uses 20W; 15W was never in the list. |
| C9 | Every entry has a permanent ID, and the file has a schema_version | IDs never change when a name changes, so a later catalogue pull can tell a rename from a new entry. |
| C10 | The adapters' inline connector values became the named list adapter_connectors |  |
| C11 | Units are written without a space, and printed values use title case | 0.25m, 1m, 1.5m, 2m, 3m; Wallet Flip; Tempered Glass. Search treats "1 m" and "1m" as the same. |
| C12 | Gray became Grey | "gray" stays a search word. |
| C13 | Mouse and Keyboard gained a Connectivity facet | Wired, Wireless or Bluetooth, printed before the word: "Wireless Mouse". |
| F4 | Cases print as "Pouch", with the pouch type before the word | "iPhone 11 Silicone Pouch". The category is now "Phone pouches and covers"; "case", "phone case" and "cover" are search words. Screen protectors follow the same order ("Tecno Spark 20 Tempered Glass Screen Protector"); confirm with the owner. |
| F5 | "earpods" became a search word for Earbuds |  |
| R1 | Each category has a drill order for the tap-through tiles | Proposed defaults; speed data from the shadow pilot confirms them (D12). |
| R2 | The 127 stored generated lines became 5 generator rules | The same 127 shapes are offered at the counter and an item is created on its first sale, so there is no prune review at setup. |

## C10. Still open

- C8: brands shown first for Wireless chargers, Phone holders, Memory cards and flash drives, Batteries, Selfie sticks, ring lights and tripods, and Computer accessories are assigned at the shelf walk.
- The 16 brands marked "To confirm on the shelf walk", and the short codes HF, AI, LP, BM, DV, JZ, EX and UWO.
- Oraimo "Watch" prints as "Oraimo Watch Smartwatch"; rename it if the owner prefers.
- Phone models are a first draft: add, rename or remove against the shelf and supplier lists.
- Drill orders are proposals until speed data from the shadow pilot confirms them (D12).
