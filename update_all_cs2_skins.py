import urllib.request
import json
import sqlite3
import re
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "cs2_simulator.db"

url = 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/crates.json'
print("Fetching complete CS2 Crates and Weapon Skins from ByMyKel CSGO-API...")
req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
with urllib.request.urlopen(req) as resp:
    crates_data = json.loads(resp.read().decode('utf-8'))

print(f"Loaded {len(crates_data)} total crates from API.")

# Filter only weapon cases (exclude sticker capsules, pin capsules, patch packs, souvenir packages)
excluded_keywords = ['capsule', 'souvenir', 'package', 'pin', 'patch', 'music kit', 'gift', 'autograph']
weapon_cases = []
for c in crates_data:
    name = c.get('name', '')
    name_lower = name.lower()
    c_type = c.get('type', '')
    
    if any(k in name_lower for k in excluded_keywords):
        continue
    if c_type == 'Case' or 'case' in name_lower:
        if len(c.get('contains', [])) > 0:
            weapon_cases.append(c)

print(f"Identified {len(weapon_cases)} valid CS2 weapon cases.")

# Pricing guide for cases based on rarity/age
case_price_map = {
    'CS:GO Weapon Case': 85.00,
    'Operation Bravo Case': 65.00,
    'CS:GO Weapon Case 2': 18.50,
    'CS:GO Weapon Case 3': 9.20,
    'eSports 2013 Case': 48.00,
    'eSports 2013 Winter Case': 12.00,
    'eSports 2014 Summer Case': 11.50,
    'Winter Offensive Weapon Case': 14.00,
    'Operation Phoenix Weapon Case': 4.50,
    'Huntsman Weapon Case': 14.50,
    'Operation Breakout Weapon Case': 8.50,
    'Operation Vanguard Weapon Case': 3.80,
    'Chroma Case': 4.20,
    'Chroma 2 Case': 3.10,
    'Chroma 3 Case': 2.80,
    'Falchion Case': 1.60,
    'Shadow Case': 1.40,
    'Revolver Case': 2.20,
    'Operation Wildfire Case': 4.10,
    'Gamma Case': 3.50,
    'Gamma 2 Case': 2.90,
    'Glove Case': 8.50,
    'Spectrum Case': 3.60,
    'Spectrum 2 Case': 2.70,
    'Operation Hydra Case': 24.00,
    'Clutch Case': 3.80,
    'Horizon Case': 1.50,
    'Danger Zone Case': 1.30,
    'Prisma Case': 1.20,
    'Prisma 2 Case': 2.45,
    'CS20 Case': 1.10,
    'Shattered Web Case': 4.90,
    'Fracture Case': 2.70,
    'Operation Broken Fang Case': 5.80,
    'Snakebite Case': 2.50,
    'Operation Riptide Case': 6.50,
    'Dreams & Nightmares Case': 2.90,
    'Recoil Case': 2.55,
    'Revolution Case': 2.65,
    'Kilowatt Case': 3.49,
    'Gallery Case': 4.20,
    'Fever Case': 2.50
}

def map_rarity(rarity_info):
    name = rarity_info.get('name', '') if isinstance(rarity_info, dict) else str(rarity_info)
    name_lower = name.lower()
    if 'mil-spec' in name_lower or 'milspec' in name_lower or 'blue' in name_lower:
        return {'id': 'mil-spec', 'name': 'Mil-Spec Grade', 'color': '#4b69ff', 'tier': 1}
    elif 'restricted' in name_lower or 'purple' in name_lower:
        return {'id': 'restricted', 'name': 'Restricted', 'color': '#8847ff', 'tier': 2}
    elif 'classified' in name_lower or 'pink' in name_lower:
        return {'id': 'classified', 'name': 'Classified', 'color': '#d32ce6', 'tier': 3}
    elif 'covert' in name_lower or 'red' in name_lower:
        return {'id': 'covert', 'name': 'Covert', 'color': '#eb4b4b', 'tier': 4}
    elif 'extraordinary' in name_lower or 'knife' in name_lower or 'glove' in name_lower or 'special' in name_lower or 'gold' in name_lower:
        return {'id': 'special', 'name': '★ Special Rare', 'color': '#ffd700', 'tier': 5}
    return {'id': 'mil-spec', 'name': 'Mil-Spec Grade', 'color': '#4b69ff', 'tier': 1}

def estimate_base_price(rarity_id, weapon_name, skin_name, case_name):
    tier_ranges = {
        'mil-spec': (0.15, 2.50),
        'restricted': (2.20, 15.00),
        'classified': (15.00, 85.00),
        'covert': (60.00, 550.00),
        'special': (250.00, 3200.00)
    }
    low, high = tier_ranges.get(rarity_id, (0.5, 2.0))
    multipliers = {
        'AWP': 1.8, 'AK-47': 1.6, 'M4A4': 1.4, 'M4A1-S': 1.4,
        'Desert Eagle': 1.4, 'USP-S': 1.3, 'Glock-18': 1.2,
        'Karambit': 2.5, 'Butterfly Knife': 3.2, 'M9 Bayonet': 2.3,
        'Skeleton Knife': 2.0, 'Kukri Knife': 1.9, 'Sport Gloves': 2.8,
        'Specialist Gloves': 2.2, 'Talon Knife': 1.8
    }
    mult = 1.0
    for w, m in multipliers.items():
        if w.lower() in weapon_name.lower():
            mult = max(mult, m)
            
    # Grail skins multipliers
    if 'Printstream' in skin_name: mult *= 1.8
    elif 'Fire Serpent' in skin_name: mult *= 5.0
    elif 'Case Hardened' in skin_name and 'Weapon Case' in case_name: mult *= 4.5
    elif 'Lightning Strike' in skin_name: mult *= 3.5
    elif 'Inheritance' in skin_name or 'Chrome Cannon' in skin_name: mult *= 1.5
    elif 'Vaporwave' in skin_name or 'The Outsiders' in skin_name: mult *= 1.6
    elif 'Fade' in skin_name or 'Doppler' in skin_name or 'Marble Fade' in skin_name: mult *= 2.4
    elif 'Vice' in skin_name or 'Pandora' in skin_name: mult *= 4.0
    elif 'Lore' in skin_name or 'Crimson Web' in skin_name: mult *= 2.5

    seed = (sum(ord(c) for c in (weapon_name + skin_name)) % 100) / 100.0
    val = (low + seed * (high - low)) * mult
    return round(val, 2)

parsed_cases = []

for crate in weapon_cases:
    name = crate.get('name')
    case_price = case_price_map.get(name, 2.50)
    case_id = crate.get('id', name.lower().replace(' ', '-').replace("'", ""))

    case_obj = {
        "id": case_id,
        "name": name,
        "description": crate.get('description') or f"Official CS2 {name}.",
        "price": case_price,
        "key_price": 2.49,
        "image": crate.get('image', ''),
        "items": []
    }

    # Standard skins
    for item in crate.get('contains', []):
        full_name = item.get('name', '')
        if '|' in full_name:
            weapon, skin = [p.strip() for p in full_name.split('|', 1)]
        else:
            weapon, skin = full_name, ''

        rarity_obj = map_rarity(item.get('rarity', {}))
        price = estimate_base_price(rarity_obj['id'], weapon, skin, name)
        min_float = item.get('min_float') if item.get('min_float') is not None else 0.00
        max_float = item.get('max_float') if item.get('max_float') is not None else 1.00

        skin_id = f"{case_id}_{weapon}_{skin}".lower().replace(' ', '_').replace('★_', '').replace('|', '').replace('(', '').replace(')', '').replace("'", "").replace('.', '')

        case_obj['items'].append({
            "id": skin_id,
            "fullName": full_name,
            "weapon": weapon,
            "skin": skin,
            "rarity": rarity_obj['id'],
            "name": rarity_obj['name'],
            "color": rarity_obj['color'],
            "tier": rarity_obj['tier'],
            "price": price,
            "min_float": round(min_float, 2),
            "max_float": round(max_float, 2),
            "image": item.get('image', '')
        })

    # Rare items (Knives / Gloves) - take top 12 iconic finishes per case
    rares = crate.get('contains_rare', [])
    selected_rares = rares[:12] if len(rares) > 12 else rares
    for item in selected_rares:
        full_name = item.get('name', '')
        if '|' in full_name:
            weapon, skin = [p.strip() for p in full_name.split('|', 1)]
            weapon = weapon.replace('★', '').strip()
        else:
            weapon, skin = full_name.replace('★', '').strip(), ''

        rarity_obj = {'id': 'special', 'name': '★ Special Rare', 'color': '#ffd700', 'tier': 5}
        price = estimate_base_price('special', weapon, skin, name)
        skin_id = f"{case_id}_rare_{weapon}_{skin}".lower().replace(' ', '_').replace('★_', '').replace('|', '').replace('(', '').replace(')', '').replace("'", "").replace('.', '')

        case_obj['items'].append({
            "id": skin_id,
            "fullName": f"★ {weapon} | {skin}",
            "weapon": f"★ {weapon}",
            "skin": skin,
            "rarity": 'special',
            "name": '★ Special Rare',
            "color": '#ffd700',
            "tier": 5,
            "price": price,
            "min_float": 0.00,
            "max_float": 0.80,
            "image": item.get('image', '')
        })

    parsed_cases.append(case_obj)
    print(f" - {case_obj['name']}: {len(case_obj['items'])} skins (includes {len(selected_rares)} knives/gloves)")

print(f"\nTotal parsed cases: {len(parsed_cases)}")
total_skins = sum(len(c['items']) for c in parsed_cases)
print(f"Total weapon skins/knives/gloves: {total_skins}")

# Write to database
print("\nUpdating SQLite database with all CS2 cases and skins...")
with sqlite3.connect(str(DB_PATH)) as conn:
    cursor = conn.cursor()
    cursor.execute("DELETE FROM skins")
    cursor.execute("DELETE FROM cases")
    for case in parsed_cases:
        cursor.execute("""
            INSERT OR REPLACE INTO cases (id, name, description, price, key_price, image, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        """, (case['id'], case['name'], case['description'], case['price'], case['key_price'], case['image']))

        for item in case['items']:
            full_name = item.get('fullName') or f"{item['weapon']} | {item['skin']}"
            cursor.execute("""
                INSERT OR REPLACE INTO skins (
                    id, case_id, name, weapon, skin_name, rarity, rarity_name, 
                    rarity_color, rarity_tier, image, base_price, min_float, max_float, can_be_stattrak
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            """, (
                item['id'], case['id'], full_name, item['weapon'], item['skin'],
                item['rarity'], item['name'], item['color'], item['tier'],
                item['image'], item['price'], item.get('min_float', 0.0), item.get('max_float', 1.0)
            ))
    conn.commit()

print(f"Database successfully updated with {len(parsed_cases)} cases and {total_skins} skins!")

# Also update app/seed_data.py
seed_py_path = BASE_DIR / "app" / "seed_data.py"
with open(seed_py_path, 'w', encoding='utf-8') as f:
    f.write('import json\n')
    f.write('from app.database import get_db_connection\n')
    f.write('from app.logging_config import logger\n\n')
    f.write('PRESET_CASES = ')
    json.dump(parsed_cases, f, indent=4, ensure_ascii=False)
    f.write('\n\n')
    f.write('''def seed_cases_and_skins():
    """Seeds presets into database, ensuring updated images and skins."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) as count FROM cases")
        row = cursor.fetchone()
        if row and row['count'] >= len(PRESET_CASES):
            return
            
        logger.info("Syncing updated CS2 Cases and Skins into database...")
        cursor.execute("DELETE FROM skins")
        cursor.execute("DELETE FROM cases")
        
        for case in PRESET_CASES:
            cursor.execute("""
                INSERT OR REPLACE INTO cases (id, name, description, price, key_price, image, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            """, (case['id'], case['name'], case['description'], case['price'], case['key_price'], case['image']))

            for item in case['items']:
                full_name = item.get('fullName') or f"{item['weapon']} | {item['skin']}"
                cursor.execute("""
                    INSERT OR REPLACE INTO skins (
                        id, case_id, name, weapon, skin_name, rarity, rarity_name, 
                        rarity_color, rarity_tier, image, base_price, min_float, max_float, can_be_stattrak
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                """, (
                    item['id'], case['id'], full_name, item['weapon'], item['skin'],
                    item['rarity'], item['name'], item['color'], item['tier'],
                    item['image'], item['price'], item.get('min_float', 0.0), item.get('max_float', 1.0)
                ))

        logger.info(f"Successfully seeded {len(PRESET_CASES)} cases and their full weapon loadouts with official images.")
''')

print("seed_data.py updated successfully!")
