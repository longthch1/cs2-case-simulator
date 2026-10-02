<?php
/**
 * Daily Reward API
 * Handles daily streak tracking and reward claiming.
 *
 * Reward schedule:
 *   Day 1 = $5, Day 2 = $8, Day 3 = $12, Day 4 = $18,
 *   Day 5 = $25, Day 6 = $35, Day 7+ = $50 (cycle resets after 7)
 *
 * Streak resets if more than 48 hours have elapsed since last claim.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';

/** Daily reward amounts indexed by streak day (1-based, day 7 repeats for 7+). */
const DAILY_REWARDS = [
    1 => 5.00,
    2 => 8.00,
    3 => 12.00,
    4 => 18.00,
    5 => 25.00,
    6 => 35.00,
    7 => 50.00,
];

/** Maximum hours before streak resets. */
const STREAK_RESET_HOURS = 48;

setCorsHeaders();

$method = getMethod();
$action = getApiPath('daily');

try {
    $pdo = getDB();

    switch ($method) {
        case 'GET':
            if ($action === 'status') {
                handleDailyStatus($pdo);
            } else {
                jsonError('Not found', 404);
            }
            break;

        case 'POST':
            if ($action === 'claim') {
                handleDailyClaim($pdo);
            } else {
                jsonError('Not found', 404);
            }
            break;

        default:
            jsonError('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log('Daily API DB error: ' . $e->getMessage());
    jsonError('Database error', 500);
} catch (Throwable $e) {
    error_log('Daily API error: ' . $e->getMessage());
    jsonError('Internal server error', 500);
}

// ---------------------------------------------------------------------------
// GET /api/daily/status
// Returns current streak info and eligibility to claim
// ---------------------------------------------------------------------------
function handleDailyStatus(PDO $pdo): void
{
    $user = requireAuth();

    $stmt = $pdo->prepare("
        SELECT last_daily_claim, daily_streak
        FROM users
        WHERE id = :id
    ");
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        jsonError('User not found', 404);
    }

    $now             = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $lastClaim       = $row['last_daily_claim']
                        ? new DateTimeImmutable($row['last_daily_claim'], new DateTimeZone('UTC'))
                        : null;
    $currentStreak   = (int)$row['daily_streak'];

    [$canClaim, $hoursUntilNext, $streakAfterClaim] = evaluateDailyEligibility(
        $now,
        $lastClaim,
        $currentStreak
    );

    $rewardDay        = min($streakAfterClaim, 7);
    $nextRewardAmount = DAILY_REWARDS[$rewardDay];

    $response = [
        'success'             => true,
        'can_claim'           => $canClaim,
        'current_streak'      => $currentStreak,
        'next_streak'         => $streakAfterClaim,
        'next_reward_amount'  => $nextRewardAmount,
        'last_claim'          => $lastClaim?->format('Y-m-d H:i:s'),
        'hours_until_next'    => $canClaim ? 0 : $hoursUntilNext,
        'reward_schedule'     => DAILY_REWARDS,
    ];

    if ($lastClaim) {
        $response['seconds_until_next'] = $canClaim
            ? 0
            : max(0, ($lastClaim->getTimestamp() + 86400) - $now->getTimestamp());
    }

    jsonResponse($response);
}

// ---------------------------------------------------------------------------
// POST /api/daily/claim
// Claims the daily reward if eligible
// ---------------------------------------------------------------------------
function handleDailyClaim(PDO $pdo): void
{
    $user = requireAuth();

    $pdo->beginTransaction();
    try {
        // Lock user row
        $stmt = $pdo->prepare("
            SELECT last_daily_claim, daily_streak, balance
            FROM users
            WHERE id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            jsonError('User not found', 404);
        }

        $now           = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lastClaim     = $row['last_daily_claim']
                          ? new DateTimeImmutable($row['last_daily_claim'], new DateTimeZone('UTC'))
                          : null;
        $currentStreak = (int)$row['daily_streak'];

        [$canClaim, , $newStreak] = evaluateDailyEligibility($now, $lastClaim, $currentStreak);

        if (!$canClaim) {
            $pdo->rollBack();

            $secondsLeft = $lastClaim
                ? max(0, ($lastClaim->getTimestamp() + 86400) - $now->getTimestamp())
                : 0;

            jsonError('Daily reward not available yet. Try again in ' . formatDuration($secondsLeft) . '.', 429, [
                'seconds_until_next' => $secondsLeft,
            ]);
        }

        $rewardDay    = min($newStreak, 7);
        $rewardAmount = DAILY_REWARDS[$rewardDay];

        // Update user: streak, last claim, balance
        $upd = $pdo->prepare("
            UPDATE users
            SET balance          = balance + :reward,
                daily_streak     = :streak,
                last_daily_claim = :last_claim
            WHERE id = :user_id
        ");
        $upd->execute([
            ':reward'     => $rewardAmount,
            ':streak'     => $newStreak,
            ':last_claim' => $now->format('Y-m-d H:i:s'),
            ':user_id'    => $user['id'],
        ]);

        // Record transaction
        $ins = $pdo->prepare("
            INSERT INTO transactions (user_id, type, amount, description, created_at)
            VALUES (:user_id, 'daily_reward', :amount, :description, NOW())
        ");
        $ins->execute([
            ':user_id'     => $user['id'],
            ':amount'      => $rewardAmount,
            ':description' => "Daily reward - Day {$newStreak} streak",
        ]);

        // Fetch updated balance
        $balStmt = $pdo->prepare("SELECT balance FROM users WHERE id = :id");
        $balStmt->execute([':id' => $user['id']]);
        $newBalance = (float)$balStmt->fetchColumn();

        $pdo->commit();

        $nextStreakDay   = min($newStreak + 1, 7);
        $nextReward      = DAILY_REWARDS[$nextStreakDay];

        jsonResponse([
            'success'          => true,
            'reward_amount'    => $rewardAmount,
            'new_balance'      => $newBalance,
            'new_streak'       => $newStreak,
            'streak_day'       => $rewardDay,
            'next_reward'      => $nextReward,
            'message'          => "Day {$newStreak} streak! You received \${$rewardAmount}",
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Evaluate whether the user can claim their daily reward.
 *
 * Rules:
 *  - If never claimed: can claim, streak becomes 1
 *  - If last claim was 24-48 hours ago: can claim, streak continues (+1)
 *  - If last claim was < 24 hours ago: cannot claim
 *  - If last claim was > 48 hours ago: can claim but streak resets to 1
 *
 * @return array{bool, int, int} [canClaim, hoursUntilNext, newStreak]
 */
function evaluateDailyEligibility(
    DateTimeImmutable $now,
    ?DateTimeImmutable $lastClaim,
    int $currentStreak
): array {
    if ($lastClaim === null) {
        // First time claiming
        return [true, 0, 1];
    }

    $diff  = $now->getTimestamp() - $lastClaim->getTimestamp();
    $hours = $diff / 3600;

    if ($hours < 24) {
        // Too soon - calculate hours remaining
        $hoursUntilNext = (int)ceil(24 - $hours);
        return [false, $hoursUntilNext, $currentStreak];
    }

    if ($hours > STREAK_RESET_HOURS) {
        // Missed too many days - streak resets
        return [true, 0, 1];
    }

    // Within 24-48h window - streak continues
    $newStreak = $currentStreak + 1;
    return [true, 0, $newStreak];
}

/**
 * Format a duration in seconds to a human-readable string.
 */
function formatDuration(int $seconds): string
{
    if ($seconds < 60) {
        return "{$seconds} second(s)";
    }
    if ($seconds < 3600) {
        $mins = (int)ceil($seconds / 60);
        return "{$mins} minute(s)";
    }
    $hours = (int)ceil($seconds / 3600);
    return "{$hours} hour(s)";
}
