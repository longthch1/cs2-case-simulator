import random
from fastapi import APIRouter, HTTPException, Depends
from app.models import TradeUpRequest
from app.database import get_db_connection
from app.auth import get_current_user
from app.security import ProvablyFairRNG
from app.monitoring import metrics
from app.logging_config import audit_logger

router = APIRouter(prefix="/api/tradeup", tags=["Trade-Up Contract"])

@router.post("")
async def execute_tradeup(req: TradeUpRequest, current_user: dict = Depends(get_current_user)):
    """
    Executes a CS2 Trade-Up Contract:
    10 skins of the same tier -> 1 skin of the next higher tier.
    Applies authentic CS2 wear float formula and StatTrak transfer rules.
    """
    user_id = current_user['id']
    unique_ids = list(set(req.inventory_ids))
    if len(unique_ids) != 10:
        raise HTTPException(status_code=400, detail="Exactly 10 distinct inventory items are required")

    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        # Fetch all 10 items with skin details
        placeholders = ",".join("?" for _ in unique_ids)
        cursor.execute(f"""
            SELECT i.id, i.float_value, i.is_stattrak, i.value,
                   s.id as skin_id, s.case_id, s.rarity_tier, s.rarity, s.name, s.weapon
            FROM inventory i
            JOIN skins s ON i.skin_id = s.id
            WHERE i.id IN ({placeholders}) AND i.user_id = ? AND i.is_sold = 0
        """, (*unique_ids, user_id))
        items = [dict(r) for r in cursor.fetchall()]

        if len(items) != 10:
            raise HTTPException(status_code=400, detail="One or more items are not found or already sold")

        # Verify all 10 items have the same rarity tier
        tiers = set(item['rarity_tier'] for item in items)
        if len(tiers) != 1:
            raise HTTPException(status_code=400, detail="All 10 trade-up items must be of the exact same rarity tier")

        input_tier = list(tiers)[0]
        if input_tier >= 5:
            raise HTTPException(status_code=400, detail="Special Rare items (Knives/Gloves) cannot be traded up")

        output_tier = input_tier + 1

        # Calculate average float of input items
        avg_float = sum(it['float_value'] for it in items) / 10.0

        # StatTrak probability = count of stattrak inputs / 10
        st_count = sum(1 for it in items if it['is_stattrak'])
        is_output_st = random.random() < (st_count / 10.0)

        # Collect possible collections / cases from inputs
        input_cases = [it['case_id'] for it in items]
        chosen_case_id = random.choice(input_cases)

        # Find available higher tier skins for this case
        cursor.execute("""
            SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
            FROM skins
            WHERE case_id = ? AND rarity_tier = ?
        """, (chosen_case_id, output_tier))
        possible_outputs = [dict(r) for r in cursor.fetchall()]

        # If chosen case has no skins in that tier, fallback to any skin of that tier
        if not possible_outputs:
            cursor.execute("""
                SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
                FROM skins
                WHERE rarity_tier = ?
            """, (output_tier,))
            possible_outputs = [dict(r) for r in cursor.fetchall()]

        if not possible_outputs:
            raise HTTPException(status_code=500, detail="No valid target tier skins available for trade-up")

        target_skin = random.choice(possible_outputs)

        # Official CS2 wear calculation formula:
        # Output Float = MinFloat + AvgFloat * (MaxFloat - MinFloat)
        output_float = round(target_skin['min_float'] + avg_float * (target_skin['max_float'] - target_skin['min_float']), 6)
        wear_name, wear_mult = ProvablyFairRNG.get_wear_condition(output_float)
        st_mult = 2.2 if is_output_st else 1.0
        output_value = round(target_skin['base_price'] * wear_mult * st_mult, 2)

        # Consume the 10 input items
        cursor.execute(f"UPDATE inventory SET is_sold = 1 WHERE id IN ({placeholders})", (*unique_ids,))

        # Insert newly crafted item into inventory
        cursor.execute("""
            INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        """, (user_id, target_skin['id'], output_float, wear_name, 1 if is_output_st else 0, output_value))
        new_inv_id = cursor.lastrowid

        # Record trade-up contract history
        cursor.execute("""
            INSERT INTO tradeup_history (user_id, input_inventory_ids, output_inventory_id, input_tier, output_tier, output_float)
            VALUES (?, ?, ?, ?, ?, ?)
        """, (user_id, ",".join(str(i) for i in unique_ids), new_inv_id, input_tier, output_tier, output_float))

        metrics.record_tradeup()
        audit_logger.info(f"User {current_user['username']} executed Trade-Up: 10x Tier {input_tier} -> 1x {target_skin['name']} (${output_value:.2f})")

        return {
            "success": True,
            "reward": {
                "inventory_id": new_inv_id,
                "skin_id": target_skin['id'],
                "name": target_skin['name'],
                "weapon": target_skin['weapon'],
                "skin_name": target_skin['skin_name'],
                "rarity": target_skin['rarity'],
                "rarity_name": target_skin['rarity_name'],
                "rarity_color": target_skin['rarity_color'],
                "rarity_tier": output_tier,
                "image": target_skin['image'],
                "float_value": output_float,
                "wear_name": wear_name,
                "is_stattrak": is_output_st,
                "value": output_value
            }
        }
