from typing import Optional
from fastapi import APIRouter, HTTPException, Depends
from app.database import get_db_connection
from app.auth import get_current_user
from app.logging_config import audit_logger

router = APIRouter(prefix="/api/inventory", tags=["Inventory"])

@router.get("")
async def get_inventory(
    rarity_tier: Optional[int] = None,
    is_stattrak: Optional[int] = None,
    sort_by: str = "date_desc",
    current_user: dict = Depends(get_current_user)
):
    """Fetches user inventory with filtering and sorting."""
    user_id = current_user['id']
    query = """
        SELECT i.id, i.float_value, i.wear_name, i.is_stattrak, i.value, i.acquired_at,
               s.id as skin_id, s.name, s.weapon, s.skin_name, s.rarity, s.rarity_name, 
               s.rarity_color, s.rarity_tier, s.image
        FROM inventory i
        JOIN skins s ON i.skin_id = s.id
        WHERE i.user_id = ? AND i.is_sold = 0
    """
    params = [user_id]

    if rarity_tier is not None:
        query += " AND s.rarity_tier = ?"
        params.append(rarity_tier)

    if is_stattrak is not None:
        query += " AND i.is_stattrak = ?"
        params.append(is_stattrak)

    sort_options = {
        "date_desc": " ORDER BY i.acquired_at DESC",
        "date_asc": " ORDER BY i.acquired_at ASC",
        "value_desc": " ORDER BY i.value DESC",
        "value_asc": " ORDER BY i.value ASC",
        "float_asc": " ORDER BY i.float_value ASC",
        "rarity_desc": " ORDER BY s.rarity_tier DESC, i.value DESC"
    }
    query += sort_options.get(sort_by, " ORDER BY i.acquired_at DESC")

    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute(query, params)
        items = [dict(row) for row in cursor.fetchall()]

        cursor.execute("SELECT COUNT(*) as count, COALESCE(SUM(value), 0) as total_val FROM inventory WHERE user_id = ? AND is_sold = 0", (user_id,))
        summary = cursor.fetchone()

        return {
            "items": items,
            "total_count": summary['count'],
            "total_value": round(summary['total_val'], 2)
        }

@router.post("/{inventory_id}/sell")
async def sell_item(inventory_id: int, current_user: dict = Depends(get_current_user)):
    """Sells a single skin and adds funds to user wallet."""
    user_id = current_user['id']
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("""
            SELECT i.id, i.value, s.name 
            FROM inventory i
            JOIN skins s ON i.skin_id = s.id
            WHERE i.id = ? AND i.user_id = ? AND i.is_sold = 0
        """, (inventory_id, user_id))
        item = cursor.fetchone()
        if not item:
            raise HTTPException(status_code=404, detail="Item not found or already sold")

        sale_amount = item['value']

        # Update item status
        cursor.execute("UPDATE inventory SET is_sold = 1 WHERE id = ?", (inventory_id,))

        # Update user balance
        cursor.execute("UPDATE users SET balance = balance + ? WHERE id = ?", (sale_amount, user_id))
        
        cursor.execute("SELECT balance FROM users WHERE id = ?", (user_id,))
        new_balance = round(cursor.fetchone()['balance'], 2)

        # Log transaction
        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'skin_sell', ?, ?, ?)
        """, (user_id, sale_amount, new_balance, f"Sold {item['name']} for ${sale_amount:.2f}"))

        audit_logger.info(f"User {current_user['username']} sold item #{inventory_id} ({item['name']}) for ${sale_amount:.2f}")

        return {
            "success": True,
            "sold_item_id": inventory_id,
            "amount": sale_amount,
            "new_balance": new_balance
        }

@router.post("/sell-all")
async def sell_all_items(current_user: dict = Depends(get_current_user)):
    """Quick sells all unsold inventory items."""
    user_id = current_user['id']
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT id, value FROM inventory WHERE user_id = ? AND is_sold = 0", (user_id,))
        items = cursor.fetchall()
        if not items:
            return {"success": True, "sold_count": 0, "amount": 0.0, "new_balance": current_user['balance']}

        total_amount = round(sum(it['value'] for it in items), 2)
        cursor.execute("UPDATE inventory SET is_sold = 1 WHERE user_id = ? AND is_sold = 0", (user_id,))
        cursor.execute("UPDATE users SET balance = balance + ? WHERE id = ?", (total_amount, user_id))
        
        cursor.execute("SELECT balance FROM users WHERE id = ?", (user_id,))
        new_balance = round(cursor.fetchone()['balance'], 2)

        cursor.execute("""
            INSERT INTO transactions (user_id, type, amount, balance_after, description)
            VALUES (?, 'skin_sell', ?, ?, ?)
        """, (user_id, total_amount, new_balance, f"Sold all {len(items)} inventory items"))

        audit_logger.info(f"User {current_user['username']} sold all {len(items)} items for ${total_amount:.2f}")

        return {
            "success": True,
            "sold_count": len(items),
            "amount": total_amount,
            "new_balance": new_balance
        }
