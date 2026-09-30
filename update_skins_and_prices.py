import urllib.request
import json
import sqlite3
import random
import os
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding='utf-8')

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "cs2_simulator.db"

print("1. Fetching skins.json from ByMyKel CSGO-API...")
skins_url = 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/skins.json'
req = urllib.request.Request(skins_url, headers={'User-Agent': 'Mozilla/5.0'})
with urllib.request.urlopen(req) as resp:
    all_api_skins = json.loads(resp.read().decode('utf-8'))

print(f"Loaded {len(all_api_skins)} skins from API.")

# Filter Consumer Grade skins (Bậc Xám)
consumer_skins = []
for s in all_api_skins:
    rarity_data = s.get('rarity')
    r_name = rarity_data.get('name') if isinstance(rarity_data, dict) else str(rarity_data)
    if 'consumer' in str(r_name).lower():
        consumer_skins.append(s)

print(f"Identified {len(consumer_skins)} Consumer Grade (Bậc Xám) skins.")

# Connect to database
conn = sqlite3.connect(str(DB_PATH))
cursor = conn.cursor()

# 2. Add Souvenir / Collection packages that feature Consumer Grade skins if not present
collection_crates = [
    {
        'id': 'crate-souvenir-cobblestone',
        'name': 'Cobblestone Souvenir Package',
        'description': 'Gói quà lưu niệm Cobblestone danh giá, cơ hội sở hữu AWP Dragon Lore và các skin Bậc Xám huyền thoại.',
        'price': 9.90,
        'image': 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj25UrzThOjzse4wjJJ4P-hV6BoH_ORDGuVj-gi57M5TXzqx0pwsj_UzIv4dHufOAEmApZ1E-8P5BXsxIHnY-O2sQPAy9USiW38NTw'
    },
    {
        'id': 'crate-souvenir-anubis',
        'name': 'Anubis Collection Package',
        'description': 'Bộ sưu tập Anubis CS2 với M4A4 Eye of Horus và hàng loạt skin Bậc Xám ấn tượng.',
        'price': 6.50,
        'image': 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj15EzlSA7OjYLv7ydL_f2jZ-o7daKQCzeWkugh4OcwHXjmwkh-4jzXw4uqJHiROARzX8AjFuUN5Ba8jJS5YMSRiKqp'
    },
    {
        'id': 'crate-souvenir-dust2',
        'name': '2021 Dust II Souvenir Package',
        'description': 'Gói quà lưu niệm Dust II 2021 với AK-47 Gold Arabesque và các vũ khí Bậc Xám kinh điển.',
        'price': 8.50,
        'image': 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bjn_lbkShWjzsSxwjJJ4P-hV6BoH_SGHXPCj-gg4-M4Sn-wxR9x4j6HmN-gcC_CPVIhCMB2QOZZthTqm9TuMujm5Q3Ay9USPT9sqvk'
    },
    {
        'id': 'crate-souvenir-mirage',
        'name': '2021 Mirage Souvenir Package',
        'description': 'Gói quà lưu niệm Mirage 2021 với AWP Desert Hydra và skin Bậc Xám Mirage.',
        'price': 7.50,
        'image': 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bjn_lbkShWjzsSxwjJJ4P-hV6BoH_2aHGaXxKB0s7dtHiu3lkl1sTyEyN_7eHmTaA92Dsd4TbQP5hGxxNWzZei05FTalcsbmo3RwBXU'
    },
    {
        'id': 'crate-souvenir-inferno',
        'name': '2018 Inferno Souvenir Package',
        'description': 'Gói quà lưu niệm Inferno với SG 553 Integrale và các skin Bậc Xám.',
        'price': 6.80,
        'image': 'https://community.akamai.steamstatic.com/economy/image/i0CoZ81Ui0m-9KwlBY1L_18myuGuq1wfhWSaZgMttyVfPaERSR0Wqmu7LAocGJKz2lu_XsnXwtmkJjSU91dh8bj45VfjThOjzse4wjJJ4P-hV6BoH_mdCGKCz-E44LZtTS_mwE9xt2XSm42hd33DPwNxXJd3Q-8I5EbrxoKzMuPh4QePjpUFk3uQG3tvkw'
    }
]

for cc in collection_crates:
    cursor.execute("""
        INSERT OR REPLACE INTO cases (id, name, description, price, key_price, image, is_active)
        VALUES (?, ?, ?, ?, 0.0, ?, 1)
    """, (cc['id'], cc['name'], cc['description'], cc['price'], cc['image']))

# 3. Update all case prices to fluctuate in $5.00 - $10.00 range, key_price = 0.0
case_prices_map = {
    'CS:GO Weapon Case': 9.90,
    'Operation Bravo Case': 9.80,
    'Cobblestone Souvenir Package': 9.90,
    'Operation Hydra Case': 9.50,
    'eSports 2013 Case': 9.20,
    'Glove Case': 9.40,
    'Huntsman Weapon Case': 9.00,
    'Winter Offensive Weapon Case': 8.90,
    'CS:GO Weapon Case 2': 8.80,
    'eSports 2013 Winter Case': 8.70,
    'eSports 2014 Summer Case': 8.60,
    '2021 Dust II Souvenir Package': 8.50,
    'CS:GO Weapon Case 3': 8.40,
    'Operation Breakout Weapon Case': 8.20,
    'Operation Riptide Case': 8.00,
    'Operation Broken Fang Case': 7.80,
    '2021 Mirage Souvenir Package': 7.50,
    'Shattered Web Case': 7.40,
    'Operation Phoenix Weapon Case': 7.20,
    'Chroma Case': 7.00,
    'Operation Wildfire Case': 6.90,
    '2018 Inferno Souvenir Package': 6.80,
    'Clutch Case': 6.80,
    'Operation Vanguard Weapon Case': 6.70,
    'Spectrum Case': 6.60,
    'Anubis Collection Package': 6.50,
    'Gamma Case': 6.50,
    'Kilowatt Case': 6.40,
    'Chroma 2 Case': 6.20,
    'Gamma 2 Case': 6.00,
    'Dreams & Nightmares Case': 5.90,
    'Chroma 3 Case': 5.80,
    'Spectrum 2 Case': 5.70,
    'Fracture Case': 5.60,
    'Revolution Case': 5.50,
    'Recoil Case': 5.50,
    'Snakebite Case': 5.40,
    'Prisma 2 Case': 5.30,
    'Gallery Case': 5.30,
    'Fever Case': 5.20,
    'Revolver Case': 5.20,
    'Falchion Case': 5.10,
    'Horizon Case': 5.10,
    'Shadow Case': 5.00,
    'Danger Zone Case': 5.00,
    'Prisma Case': 5.00,
    'CS20 Case': 5.00,
}

cursor.execute("SELECT id, name FROM cases")
existing_cases = cursor.fetchall()
for cid, cname in existing_cases:
    # Get mapped price or random between 5.20 and 8.80
    new_price = case_prices_map.get(cname, round(random.uniform(5.10, 8.90), 2))
    cursor.execute("UPDATE cases SET price = ?, key_price = 0.0 WHERE id = ?", (new_price, cid))

print(f"Updated all case prices to fluctuate in $5.00 - $10.00 (with key_price = 0.0).")

# 4. Insert Consumer Grade skins (Tier 0) into database across cases
# Let's get list of all cases
case_ids = [r[0] for r in existing_cases]

inserted_count = 0
for idx, cs in enumerate(consumer_skins):
    skin_id = f"skin-consumer-{cs.get('id', idx)}"
    full_name = cs.get('name', 'Consumer Skin')
    
    # Split weapon and skin name (e.g. 'P250 | Sand Dune')
    if '|' in full_name:
        weapon_part, skin_part = full_name.split('|', 1)
        weapon_name = weapon_part.strip()
        skin_name = skin_part.strip()
    else:
        weapon_name = cs.get('weapon', {}).get('name', 'P250') if isinstance(cs.get('weapon'), dict) else str(cs.get('weapon', 'P250'))
        skin_name = full_name

    image_url = cs.get('image', '')
    min_fl = float(cs.get('min_float') or 0.06)
    max_fl = float(cs.get('max_float') or 0.80)
    
    # Realistic Consumer grade prices ($0.05 to $0.85, popular ones like Sand Dune or Safari Mesh slightly higher)
    if 'Sand Dune' in skin_name or 'Safari Mesh' in skin_name:
        base_price = round(random.uniform(0.35, 1.20), 2)
    else:
        base_price = round(random.uniform(0.08, 0.45), 2)

    # Assign to cases cyclically so every case has Consumer Grade drops
    assigned_case_id = case_ids[idx % len(case_ids)]

    cursor.execute("""
        INSERT OR REPLACE INTO skins (
            id, case_id, name, weapon, skin_name, 
            rarity, rarity_name, rarity_color, rarity_tier, 
            image, base_price, min_float, max_float, can_be_stattrak
        ) VALUES (?, ?, ?, ?, ?, 'consumer', 'Consumer Grade', '#b0c3d9', 0, ?, ?, ?, ?, 0)
    """, (
        skin_id, assigned_case_id, full_name, weapon_name, skin_name,
        image_url, base_price, min_fl, max_fl
    ))
    inserted_count += 1

conn.commit()
conn.close()

print(f"Successfully inserted/updated {inserted_count} Consumer Grade (Bậc Xám) skins into database!")
