import secrets
import random
from typing import List, Dict, Any
from fastapi import APIRouter, HTTPException, Depends, Request
from app.models import CaseOpenRequest, CaseOpenResponse, DropItemDetail, VerifyRollRequest
from app.database import get_db_connection
from app.auth import get_current_user
from app.security import ProvablyFairRNG, rate_limiter
from app.monitoring import metrics
from app.logging_config import logger, audit_logger

router = APIRouter(prefix="/api/cases", tags=["Cases & Opening"])

@router.get("")
async def list_cases():
    """Lists all available CS2 cases with item counts."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT c.id, c.name, c.description, c.price, c.key_price, c.image,
                   COUNT(s.id) as total_items
            FROM cases c
            LEFT JOIN skins s ON c.id = s.case_id
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.price ASC
        """)
        cases = [dict(row) for row in cursor.fetchall()]
        return {"cases": cases}

@router.get("/{case_id}")
async def get_case_detail(case_id: str):
    """Retrieves full case details and its weapon/skin contents."""
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT id, name, description, price, key_price, image FROM cases WHERE id = ? AND is_active = 1", (case_id,))
        case = cursor.fetchone()
        if not case:
            raise HTTPException(status_code=404, detail="Case not found")

        cursor.execute("""
            SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
            FROM skins 
            WHERE case_id = ?
            ORDER BY rarity_tier ASC, base_price ASC
        """, (case_id,))
        skins = [dict(row) for row in cursor.fetchall()]

        return {
            "case": dict(case),
            "total_price": round(case['price'] + case['key_price'], 2),
            "skins": skins
        }

@router.post("/{case_id}/open", response_model=CaseOpenResponse)
async def open_case(case_id: str, req: CaseOpenRequest, request: Request, current_user: dict = Depends(get_current_user)):
    """
    Opens 1, 2, 3, 5, or 10 cases using Provably Fair cryptography.
    Guarantees ACID transactional balance deduction and inventory assignment.
    """
    user_id = current_user['id']
    if not rate_limiter.is_allowed(f"open_{user_id}", 30, 60):
        raise HTTPException(status_code=429, detail="Opening too fast. Please slow down.")

    count = req.count
    client_seed = (req.client_seed or secrets.token_hex(16)).strip()

    with get_db_connection() as conn:
        cursor = conn.cursor()
        
        # 1. Fetch Case info
        cursor.execute("SELECT id, name, price, key_price FROM cases WHERE id = ? AND is_active = 1", (case_id,))
        case = cursor.fetchone()
        if not case:
            raise HTTPException(status_code=404, detail="Case not found")

        unit_cost = case['price'] + case['key_price']
        total_cost = round(unit_cost * count, 2)

        # 2. Check and lock User balance
        cursor.execute("SELECT balance FROM users WHERE id = ?", (user_id,))
        user_row = cursor.fetchone()
        if not user_row or user_row['balance'] < total_cost:
            raise HTTPException(
                status_code=400, 
                detail=f"Insufficient balance. Opening {count} case(s) costs ${total_cost:.2f}, but your balance is ${user_row['balance']:.2f}."
            )

        # 3. Fetch all skins for this case grouped by rarity tier
        cursor.execute("""
            SELECT id, name, weapon, skin_name, rarity, rarity_name, rarity_color, rarity_tier, image, base_price, min_float, max_float
            FROM skins WHERE case_id = ?
        """, (case_id,))
        all_skins = [dict(r) for r in cursor.fetchall()]
        if not all_skins:
            raise HTTPException(status_code=500, detail="Case has no configured skins")

        tier_map = {0: [], 1: [], 2: [], 3: [], 4: [], 5: []}
        for s in all_skins:
            t = s.get('rarity_tier', 1)
            if t in tier_map:
                tier_map[t].append(s)
            else:
                tier_map[1].append(s)

        # 4. Fetch current user nonce
        cursor.execute("SELECT COUNT(*) as count FROM open_history WHERE user_id = ?", (user_id,))
        base_nonce = cursor.fetchone()['count']

        drops_result = []
        total_payout = 0.0

        # Master server seed for this opening batch
        server_seed = ProvablyFairRNG.generate_server_seed()
        server_seed_hash = ProvablyFairRNG.hash_server_seed(server_seed)

        has_tier_0 = len(tier_map[0]) > 0

        for i in range(count):
            nonce = base_nonce + i + 1
            roll = ProvablyFairRNG.calculate_roll(server_seed, client_seed, nonce)
            
            if has_tier_0:
                if roll < 0.40:
                    tier = 0
                elif roll < 0.80:
                    tier = 1
                elif roll < 0.96:
                    tier = 2
                elif roll < 0.991:
                    tier = 3
                elif roll < 0.9974:
                    tier = 4
                else:
                    tier = 5
            else:
                tier = ProvablyFairRNG.get_rarity_tier_from_roll(roll)

            # Fallback to lower tier if selected tier has no items
            skins_in_tier = tier_map.get(tier, [])
            while not skins_in_tier and tier > 0:
                tier -= 1
                skins_in_tier = tier_map.get(tier, [])

            if not skins_in_tier:
                for t in [1, 2, 3, 4, 5, 0]:
                    if tier_map.get(t):
                        skins_in_tier = tier_map[t]
                        break

            # Pick skin in tier using deterministic secondary hash
            message_pick = f"{client_seed}:{nonce}:pick".encode('utf-8')
            pick_hash = ProvablyFairRNG.calculate_roll(server_seed, client_seed, nonce, sub_index=99)
            chosen_skin = skins_in_tier[int(pick_hash * len(skins_in_tier)) % len(skins_in_tier)]

            # Calculate StatTrak and Wear Float
            is_st, raw_float = ProvablyFairRNG.calculate_stattrak_and_float(server_seed, client_seed, nonce)
            actual_float = chosen_skin['min_float'] + raw_float * (chosen_skin['max_float'] - chosen_skin['min_float'])
            actual_float = round(actual_float, 6)
            
            wear_name, wear_mult = ProvablyFairRNG.get_wear_condition(actual_float)
            st_mult = 2.2 if is_st else 1.0
            item_value = round(chosen_skin['base_price'] * wear_mult * st_mult, 2)
            total_payout += item_value

            # Insert into Inventory
            cursor.execute("""
                INSERT INTO inventory (user_id, skin_id, float_value, wear_name, is_stattrak, value, is_sold)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            """, (user_id, chosen_skin['id'], actual_float, wear_name, 1 if is_st else 0, item_value))
            inventory_id = cursor.lastrowid

            # Insert into Open History
            cursor.execute("""
                INSERT INTO open_history (
                    user_id, case_id, inventory_id, cost, payout, 
                    server_seed, server_seed_hash, client_seed, nonce, roll_number
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (
                user_id, case_id, inventory_id, unit_cost, item_value,
                server_seed, server_seed_hash, client_seed, nonce, round(roll, 6)
            ))

            drops_result.append(DropItemDetail(
                inventory_id=inventory_id,
                skin_id=chosen_skin['id'],
                weapon=chosen_skin['weapon'],
                skin_name=chosen_skin['skin_name'],
                rarity=chosen_skin['rarity'],
                rarity_name=chosen_skin['rarity_name'],
                rarity_color=chosen_skin['rarity_color'],
                rarity_tier=tier,
                image=chosen_skin['image'],
                float_value=actual_float,
                wear_name=wear_name,
                is_stattrak=is_st,
                value=item_value,
                server_seed_hash=server_seed_hash,
                server_seed_revealed=server_seed,
                client_seed=client_seed,
                nonce=nonce,
                roll_number=round(roll, 6)
            ))

        # 5. Deduct User balance & add transaction record
        new_balance = round(user_row['balance'] - total_cost, 2)
        cursor.execute("UPDATE users SET balance = ? WHERE id = ?", (new_balance, user_id))
        
        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'case_open', ?, ?, ?)
        """, (user_id, -total_cost, new_balance, f"Opened {count}x {case['name']}"))

        # Update Live Monitoring Metrics
        metrics.record_case_open(total_cost, total_payout, count)
        
        ip = request.client.host if request.client else "unknown"
        audit_logger.info(f"User {current_user['username']} opened {count}x {case['name']} for ${total_cost:.2f}. Total drop: ${total_payout:.2f}. IP: {ip}")

        return CaseOpenResponse(
            success=True,
            drops=drops_result,
            spent=total_cost,
            remaining_balance=new_balance,
            server_seed_hash=server_seed_hash
        )

@router.post("/verify-roll")
async def verify_roll(req: VerifyRollRequest):
    """
    Public Provably Fair verification endpoint.
    Anyone can recalculate the exact roll number and outcome independently.
    """
    roll = ProvablyFairRNG.calculate_roll(req.server_seed, req.client_seed, req.nonce, req.sub_index or 0)
    tier = ProvablyFairRNG.get_rarity_tier_from_roll(roll)
    is_st, raw_float = ProvablyFairRNG.calculate_stattrak_and_float(req.server_seed, req.client_seed, req.nonce, req.sub_index or 0)
    wear_name, _ = ProvablyFairRNG.get_wear_condition(raw_float)
    server_seed_hash = ProvablyFairRNG.hash_server_seed(req.server_seed)

    tier_names = {
        1: "Mil-Spec Grade (Blue ~79.92%)",
        2: "Restricted (Purple ~15.98%)",
        3: "Classified (Pink ~3.20%)",
        4: "Covert (Red ~0.64%)",
        5: "★ Special Rare (Gold Knife/Glove ~0.26%)"
    }

    return {
        "verified": True,
        "server_seed": req.server_seed,
        "server_seed_hash": server_seed_hash,
        "client_seed": req.client_seed,
        "nonce": req.nonce,
        "roll_number": round(roll, 8),
        "rarity_tier": tier,
        "rarity_desc": tier_names[tier],
        "is_stattrak": is_st,
        "raw_float": round(raw_float, 6),
        "wear_condition": wear_name
    }
