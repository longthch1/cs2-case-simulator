import urllib.request
import json
import os
import random

url = 'https://raw.githubusercontent.com/ByMykel/CSGO-API/main/public/api/en/crates.json'
print("Fetching crates data from ByMyKel CSGO-API...")
req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
with urllib.request.urlopen(req) as resp:
    data = json.loads(resp.read().decode('utf-8'))

print(f"Loaded {len(data)} items from API.")

# Target cases that players love in CS2
target_cases_keywords = [
    ("Kilowatt Case", 3.20),
    ("Revolution Case", 2.65),
    ("Dreams & Nightmares Case", 2.90),
    ("Recoil Case", 2.55),
    ("Fracture Case", 2.70),
    ("Snakebite Case", 2.50),
    ("Clutch Case", 3.80),
    ("CS:GO Weapon Case", 85.00),
]

selected_cases = []

# Map rarity names to standard CS rarity
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

# Estimate reasonable market price based on rarity tier and popularity
def estimate_base_price(rarity_id, weapon_name, skin_name, case_name):
    # Tier based base prices
    tier_ranges = {
        'mil-spec': (0.15, 1.80),
        'restricted': (1.50, 8.50),
        'classified': (12.00, 65.00),
        'covert': (50.00, 450.00),
        'special': (180.00, 2200.00)
    }
    
    low, high = tier_ranges.get(rarity_id, (0.5, 2.0))
    # Give high-demand weapons higher value
    multipliers = {
        'AWP': 1.6,
        'AK-47': 1.5,
        'M4A4': 1.3,
        'M4A1-S': 1.4,
        'Desert Eagle': 1.3,
        'USP-S': 1.3,
        'Glock-18': 1.2,
        'Karambit': 2.2,
        'Butterfly Knife': 2.5,
        'M9 Bayonet': 2.0,
        'Skeleton Knife': 1.8,
        'Kukri Knife': 1.6
    }
    
    mult = 1.0
    for w, m in multipliers.items():
        if w.lower() in weapon_name.lower():
            mult = max(mult, m)
            
    # Famous grail skins
    if 'Printstream' in skin_name:
        mult *= 1.8
    elif 'Case Hardened' in skin_name and 'Weapon Case' in case_name:
        mult *= 3.5
    elif 'Lightning Strike' in skin_name:
        mult *= 2.5
    elif 'Inheritance' in skin_name or 'Chrome Cannon' in skin_name:
        mult *= 1.4
    elif 'Fade' in skin_name or 'Doppler' in skin_name or 'Marble Fade' in skin_name:
        mult *= 2.0

    seed_val = (sum(ord(c) for c in (weapon_name + skin_name)) % 100) / 100.0
    val = (low + seed_val * (high - low)) * mult
    return round(val, 2)

for target_name, case_price in target_cases_keywords:
    matched = None
    for crate in data:
        if crate.get('name') == target_name:
            matched = crate
            break
        if not matched and target_name.lower() in crate.get('name', '').lower() and (crate.get('type') == 'Case' or 'Case' in crate.get('name', '')):
            matched = crate

    if not matched:
        continue

    case_obj = {
        'id': matched.get('id', target_name.lower().replace(' ', '-')),
        'name': matched.get('name'),
        'description': matched.get('description', f'Contains skins from the {matched.get("name")}.'),
        'image': matched.get('image', ''),
        'price': case_price,
        'keyPrice': 2.49,
        'items': []
    }

    # Process normal items
    for item in matched.get('contains', []):
        full_name = item.get('name', '')
        weapon = ''
        skin = full_name
        if '|' in full_name:
            parts = full_name.split('|', 1)
            weapon = parts[0].strip()
            skin = parts[1].strip()
        else:
            weapon = full_name

        rarity_obj = map_rarity(item.get('rarity', {}))
        base_price = estimate_base_price(rarity_obj['id'], weapon, skin, matched.get('name'))
        
        # Min/max wear float limits
        min_float = item.get('min_float', 0.0) if item.get('min_float') is not None else 0.00
        max_float = item.get('max_float', 1.0) if item.get('max_float') is not None else 1.00

        case_obj['items'].append({
            'id': item.get('id', f"{weapon}_{skin}".lower().replace(' ', '_')),
            'fullName': full_name,
            'weapon': weapon,
            'skin': skin,
            'rarity': rarity_obj['id'],
            'rarityName': rarity_obj['name'],
            'rarityColor': rarity_obj['color'],
            'rarityTier': rarity_obj['tier'],
            'image': item.get('image', ''),
            'basePrice': base_price,
            'minFloat': round(min_float, 2),
            'maxFloat': round(max_float, 2),
            'canBeStatTrak': True
        })

    # Process rare items (Knives / Gloves) - limit to top 8 iconic ones per case so dataset is fast & clean
    rare_list = matched.get('contains_rare', [])
    # Sample top 8 or iconic rare items
    selected_rares = rare_list[:8] if len(rare_list) > 8 else rare_list
    for item in selected_rares:
        full_name = item.get('name', '')
        weapon = ''
        skin = full_name
        if '|' in full_name:
            parts = full_name.split('|', 1)
            weapon = parts[0].replace('★', '').strip()
            skin = parts[1].strip()
        else:
            weapon = full_name.replace('★', '').strip()

        rarity_obj = {'id': 'special', 'name': '★ Special Rare', 'color': '#ffd700', 'tier': 5}
        base_price = estimate_base_price('special', weapon, skin, matched.get('name'))

        case_obj['items'].append({
            'id': item.get('id', f"rare_{weapon}_{skin}".lower().replace(' ', '_')),
            'fullName': f"★ {weapon} | {skin}",
            'weapon': f"★ {weapon}",
            'skin': skin,
            'rarity': 'special',
            'rarityName': '★ Special Rare',
            'rarityColor': '#ffd700',
            'rarityTier': 5,
            'image': item.get('image', ''),
            'basePrice': base_price,
            'minFloat': 0.00,
            'maxFloat': 0.80,
            'canBeStatTrak': True
        })

    selected_cases.append(case_obj)

print(f"Successfully processed {len(selected_cases)} cases with full weapon sets.")

# Write to JavaScript file
output_path = os.path.join(os.path.dirname(__file__), 'cases-data.js')
with open(output_path, 'w', encoding='utf-8') as f:
    f.write('// CS2 Case Opening Simulator - Curated Case & Skin Database\n')
    f.write('// Generated from official game data\n')
    f.write('const CS2_CASES = ')
    json.dump(selected_cases, f, indent=2, ensure_ascii=False)
    f.write(';\n\nif (typeof module !== "undefined" && module.exports) { module.exports = CS2_CASES; }\n')

print(f"Wrote {output_path} successfully!")
