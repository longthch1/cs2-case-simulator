<?php
/**
 * Wallet API
 * Handles hourly codes, code redemption, deposits, and transaction history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';

setCorsHeaders();

$method = getMethod();
$action = getApiPath('wallet');

try {
    $pdo = getDB();

    switch ($method) {
        case 'GET':
            if ($action === 'active-code') {
                handleActiveCode($pdo);
            } elseif ($action === 'transactions') {
                handleGetTransactions($pdo);
            } else {
                jsonError('Not found', 404);
            }
            break;

        case 'POST':
            if ($action === 'redeem-code') {
                handleRedeemCode($pdo);
            } elseif ($action === 'deposit') {
                handleDeposit($pdo);
            } else {
                jsonError('Not found', 404);
            }
            break;

        default:
            jsonError('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log('Wallet API DB error: ' . $e->getMessage());
    jsonError('Database error', 500);
} catch (Throwable $e) {
    error_log('Wallet API error: ' . $e->getMessage());
    jsonError('Internal server error', 500);
}

// ---------------------------------------------------------------------------
// GET /api/wallet/active-code
// Returns (or creates) the current active hourly code.
// Works for unauthenticated users too, but includes has_redeemed if authed.
// ---------------------------------------------------------------------------
function handleActiveCode(PDO $pdo): void
{
    $user = optionalAuth();

    $pdo->beginTransaction();
    try {
        // Fetch the latest code
        $stmt = $pdo->prepare("
            SELECT id, code, reward_amount, expires_at, created_at
            FROM hourly_codes
            ORDER BY created_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $activeCode = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $needsNew = false;
        if (!$activeCode) {
            $needsNew = true;
        } else {
            $expiresAt = new DateTimeImmutable($activeCode['expires_at'], new DateTimeZone('UTC'));
            if ($now >= $expiresAt) {
                $needsNew = true;
            }
        }

        if ($needsNew) {
            $code         = generateHourlyCode();
            $rewardAmount = 100.00;
            $expiresAt    = $now->modify('+1 hour');

            $ins = $pdo->prepare("
                INSERT INTO hourly_codes (code, reward_amount, expires_at, created_at)
                VALUES (:code, :reward_amount, :expires_at, NOW())
            ");
            $ins->execute([
                ':code'          => $code,
                ':reward_amount' => $rewardAmount,
                ':expires_at'    => $expiresAt->format('Y-m-d H:i:s'),
            ]);

            $activeCode = [
                'id'            => (int)$pdo->lastInsertId(),
                'code'          => $code,
                'reward_amount' => $rewardAmount,
                'expires_at'    => $expiresAt->format('Y-m-d H:i:s'),
            ];
        }

        $pdo->commit();

        // Calculate seconds left
        $expiresAt   = new DateTimeImmutable($activeCode['expires_at'], new DateTimeZone('UTC'));
        $secondsLeft = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

        // Check if current user has already redeemed this code
        $hasRedeemed = false;
        if ($user) {
            $redeemedStmt = $pdo->prepare("
                SELECT COUNT(*) FROM code_redemptions
                WHERE hourly_code_id = :code_id AND user_id = :user_id
            ");
            $redeemedStmt->execute([
                ':code_id' => $activeCode['id'],
                ':user_id' => $user['id'],
            ]);
            $hasRedeemed = (int)$redeemedStmt->fetchColumn() > 0;
        }

        jsonResponse([
            'success'       => true,
            'code'          => $activeCode['code'],
            'reward_amount' => (float)$activeCode['reward_amount'],
            'seconds_left'  => $secondsLeft,
            'expires_at'    => $activeCode['expires_at'],
            'has_redeemed'  => $hasRedeemed,
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// POST /api/wallet/redeem-code
// Body: { "code": "123456" }
// ---------------------------------------------------------------------------
function handleRedeemCode(PDO $pdo): void
{
    $user = requireAuth();
    $body = getRequestBody();

    $code = trim((string)($body['code'] ?? ''));

    // Validate 6-digit numeric code
    if (!preg_match('/^\d{6}$/', $code)) {
        jsonError('Code must be exactly 6 digits', 400);
    }

    $pdo->beginTransaction();
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Fetch active code
        $stmt = $pdo->prepare("
            SELECT id, code, reward_amount, expires_at
            FROM hourly_codes
            ORDER BY created_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $activeCode = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activeCode) {
            $pdo->rollBack();
            jsonError('No active code found', 404);
        }

        // Check expiry
        $expiresAt = new DateTimeImmutable($activeCode['expires_at'], new DateTimeZone('UTC'));
        if ($now >= $expiresAt) {
            $pdo->rollBack();
            jsonError('The hourly code has expired', 410);
        }

        // Check code matches
        if ($code !== $activeCode['code']) {
            $pdo->rollBack();
            jsonError('Invalid code', 400);
        }

        // Check not already redeemed by this user
        $redeemedStmt = $pdo->prepare("
            SELECT COUNT(*) FROM code_redemptions
            WHERE hourly_code_id = :code_id AND user_id = :user_id
        ");
        $redeemedStmt->execute([
            ':code_id' => $activeCode['id'],
            ':user_id' => $user['id'],
        ]);
        if ((int)$redeemedStmt->fetchColumn() > 0) {
            $pdo->rollBack();
            jsonError('You have already redeemed this code', 409);
        }

        $reward = (float)$activeCode['reward_amount'];

        // Credit balance
        $balUpd = $pdo->prepare("UPDATE users SET balance = balance + :amount WHERE id = :user_id");
        $balUpd->execute([':amount' => $reward, ':user_id' => $user['id']]);

        // Record redemption
        $insRedemption = $pdo->prepare("
            INSERT INTO code_redemptions (hourly_code_id, user_id, redeemed_at)
            VALUES (:code_id, :user_id, NOW())
        ");
        $insRedemption->execute([
            ':code_id' => $activeCode['id'],
            ':user_id' => $user['id'],
        ]);

        // Record transaction
        $insTx = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, description, created_at)
            VALUES (:user_id, 'code_redeem', :amount, :description, NOW())
        ");
        $insTx->execute([
            ':user_id'     => $user['id'],
            ':amount'      => $reward,
            ':description' => "Redeemed hourly code {$code}",
        ]);

        // Fetch updated balance
        $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = :id");
        $balStmt->execute([':id' => $user['id']]);
        $newBalance = (float)$balStmt->fetchColumn();

        $pdo->commit();

        jsonResponse([
            'success'       => true,
            'reward_amount' => $reward,
            'new_balance'   => $newBalance,
            'message'       => "Code redeemed! You received \${$reward}",
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// POST /api/wallet/deposit
// Body: { "amount": float }
// Demo deposit - simply adds to balance
// ---------------------------------------------------------------------------
function handleDeposit(PDO $pdo): void
{
    $user = requireAuth();
    $body = getRequestBody();

    $amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;

    if ($amount <= 0) {
        jsonError('Amount must be greater than 0', 400);
    }

    if ($amount > 10000) {
        jsonError('Maximum deposit amount is $10,000', 400);
    }

    $amount = round($amount, 2);

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE users SET balance = balance + :amount WHERE id = :user_id");
        $upd->execute([':amount' => $amount, ':user_id' => $user['id']]);

        // Record transaction
        $ins = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, description, created_at)
            VALUES (:user_id, 'deposit', :amount, :description, NOW())
        ");
        $ins->execute([
            ':user_id'     => $user['id'],
            ':amount'      => $amount,
            ':description' => "Demo deposit of \${$amount}",
        ]);

        // Fetch updated balance
        $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = :id");
        $balStmt->execute([':id' => $user['id']]);
        $newBalance = (float)$balStmt->fetchColumn();

        $pdo->commit();

        jsonResponse([
            'success'       => true,
            'deposited'     => $amount,
            'new_balance'   => $newBalance,
            'message'       => "Successfully deposited \${$amount}",
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// GET /api/wallet/transactions
// Returns last 50 transactions for the authenticated user
// ---------------------------------------------------------------------------
function handleGetTransactions(PDO $pdo): void
{
    $user  = requireAuth();
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = :user_id");
    $countStmt->execute([':user_id' => $user['id']]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, type, amount, description, created_at
        FROM transactions
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit',   $limit,      PDO::PARAM_INT);
    $stmt->bindValue(':offset',  $offset,     PDO::PARAM_INT);
    $stmt->execute();

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($transactions as &$tx) {
        $tx['id']     = (int)$tx['id'];
        $tx['amount'] = (float)$tx['amount'];
    }
    unset($tx);

    jsonResponse([
        'success'      => true,
        'data'         => $transactions,
        'pagination'   => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Generate a random 6-digit numeric code.
 */
function generateHourlyCode(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
