import urllib.request
import json
import os
import sqlite3
from pathlib import Path

# Paths
BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "cs2_simulator.db"

url = 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/crates.json'
print("Fetching latest CS2 Crates and Weapon Skins from ByMyKel CSGO-API...")
req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
with urllib.request.urlopen(req) as resp:
    crates_data = json.loads(resp.read().decode('utf-8'))

print(f"Loaded {len(crates_data)} total crates.")

target_cases = [
    ("Kilowatt Case", 3.49, "CS2 Premiere"),
    ("Revolution Case", 2.65, "CS2 Modern"),
    ("Dreams & Nightmares Case", 2.90, "Community Art"),
    ("Recoil Case", 2.55, "Modern Tactical"),
    ("Fracture Case", 2.70, "Community Favorites"),
    ("Snakebite Case", 2.50, "Snakebite Collection"),
    ("Prisma 2 Case", 2.45, "Prisma Series"),
    ("Clutch Case", 3.80, "Glove Collection"),
    ("CS:GO Weapon Case", 85.00, "Historic Grail"),
    ("Operation Bravo Case", 60.00, "Bravo Collection"),
    ("Huntsman Weapon Case", 14.50, "Huntsman Series"),
    ("Operation Breakout Weapon Case", 8.50, "Butterfly Knife Series"),
]

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
        'mil-spec': (0.20, 2.50),
        'restricted': (2.20, 15.00),
        'classified': (15.00, 85.00),
        'covert': (60.00, 550.00),
        'special': (250.00, 2800.00)
    }
    low, high = tier_ranges.get(rarity_id, (0.5, 2.0))
    multipliers = {
        'AWP': 1.8, 'AK-47': 1.6, 'M4A4': 1.4, 'M4A1-S': 1.4,
        'Desert Eagle': 1.4, 'USP-S': 1.3, 'Glock-18': 1.2,
        'Karambit': 2.5, 'Butterfly Knife': 3.0, 'M9 Bayonet': 2.2,
        'Skeleton Knife': 2.0, 'Kukri Knife': 1.8, 'Sport Gloves': 2.8,
        'Specialist Gloves': 2.2
    }
    mult = 1.0
    for w, m in multipliers.items():
        if w.lower() in weapon_name.lower():
            mult = max(mult, m)
            
    # Grail skins
    if 'Printstream' in skin_name: mult *= 1.8
    elif 'Fire Serpent' in skin_name: mult *= 4.5
    elif 'Case Hardened' in skin_name and 'Weapon Case' in case_name: mult *= 4.0
    elif 'Lightning Strike' in skin_name: mult *= 3.0
    elif 'Inheritance' in skin_name or 'Chrome Cannon' in skin_name: mult *= 1.5
    elif 'Fade' in skin_name or 'Doppler' in skin_name or 'Marble Fade' in skin_name: mult *= 2.2
    elif 'Vice' in skin_name or 'Pandora' in skin_name: mult *= 3.5

    seed = (sum(ord(c) for c in (weapon_name + skin_name)) % 100) / 100.0
    val = (low + seed * (high - low)) * mult
    return round(val, 2)

parsed_cases = []

for target_name, case_price, tag in target_cases:
    matched = None
    for crate in crates_data:
        if crate.get('name') == target_name:
            matched = crate
            break
        if not matched and target_name.lower() in crate.get('name', '').lower() and (crate.get('type') == 'Case' or 'Case' in crate.get('name', '')):
            matched = crate

    if not matched:
        print(f"Warning: Could not match crate {target_name}")
        continue

    case_id = matched.get('id', target_name.lower().replace(' ', '-').replace("'", ""))
    case_obj = {
        "id": case_id,
        "name": matched.get('name'),
        "description": matched.get('description') or f"Official CS2 {matched.get('name')}.",
        "price": case_price,
        "key_price": 2.49,
        "image": matched.get('image', ''),
        "items": []
    }

    # Standard skins
    for item in matched.get('contains', []):
        full_name = item.get('name', '')
        if '|' in full_name:
            weapon, skin = [p.strip() for p in full_name.split('|', 1)]
        else:
            weapon, skin = full_name, ''

        rarity_obj = map_rarity(item.get('rarity', {}))
        price = estimate_base_price(rarity_obj['id'], weapon, skin, matched.get('name'))
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

    # Rare items (Knives / Gloves) - take top 10 iconic finishes
    rares = matched.get('contains_rare', [])
    selected_rares = rares[:10] if len(rares) > 10 else rares
    for item in selected_rares:
        full_name = item.get('name', '')
        if '|' in full_name:
            weapon, skin = [p.strip() for p in full_name.split('|', 1)]
            weapon = weapon.replace('★', '').strip()
        else:
            weapon, skin = full_name.replace('★', '').strip(), ''

        rarity_obj = {'id': 'special', 'name': '★ Special Rare', 'color': '#ffd700', 'tier': 5}
        price = estimate_base_price('special', weapon, skin, matched.get('name'))
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
    print(f"Processed {case_obj['name']}: {len(case_obj['items'])} skins (including {len(selected_rares)} knives/gloves)")

print(f"\nTotal parsed cases: {len(parsed_cases)}")

# 1. Update app/seed_data.py
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
        
        # Check current count
        cursor.execute("SELECT COUNT(*) as count FROM cases")
        row = cursor.fetchone()
        
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

print(f"Updated {seed_py_path} successfully!")

# 2. Directly update the SQLite database now
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

print(f"Updated SQLite database {DB_PATH} with {len(parsed_cases)} cases successfully!")
