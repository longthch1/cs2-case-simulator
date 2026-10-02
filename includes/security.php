<?php
/**
 * CS2 Case Opening Simulator — Provably Fair RNG Engine
 * PHP port of the Python ProvablyFairRNG implementation.
 */

class ProvablyFairRNG
{
    // ── Server Seed ───────────────────────────────────────────────────────────
    public static function generateServerSeed(): string {
        return bin2hex(random_bytes(32)); // 64-char hex
    }

    public static function hashServerSeed(string $seed): string {
        return hash('sha256', $seed);
    }

    // ── Core Roll (HMAC-SHA512 based, same algorithm as Python version) ───────
    public static function calculateRoll(
        string $serverSeed,
        string $clientSeed,
        int    $nonce,
        int    $subIndex = 0
    ): float {
        $message = "$clientSeed:$nonce:$subIndex";
        $raw     = hash_hmac('sha512', $message, $serverSeed, true);

        // Use first 4 bytes → uint32 → normalize to [0, 1)
        $bytes = array_values(unpack('C4', substr($raw, 0, 4)));
        $uint  = ($bytes[0] << 24) | ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];
        return $uint / 4294967296.0; // 2^32
    }

    // ── Rarity Tier (without consumer grade tier 0) ───────────────────────────
    public static function getRarityTierFromRoll(float $roll): int {
        // CS2 drop rate table (official approximation)
        if ($roll < 0.7992) return 1; // Mil-Spec  ~79.92%
        if ($roll < 0.9590) return 2; // Restricted ~15.98%
        if ($roll < 0.9910) return 3; // Classified  ~3.20%
        if ($roll < 0.9974) return 4; // Covert      ~0.64%
        return 5;                      // Special     ~0.26%
    }

    // ── StatTrak + Float ──────────────────────────────────────────────────────
    public static function calculateStatTrakAndFloat(
        string $serverSeed,
        string $clientSeed,
        int    $nonce
    ): array {
        $stRoll    = self::calculateRoll($serverSeed, $clientSeed, $nonce, 1);
        $floatRoll = self::calculateRoll($serverSeed, $clientSeed, $nonce, 2);
        $isStatTrak = $stRoll < 0.10; // 10% chance
        return [$isStatTrak, $floatRoll];
    }

    // ── Wear Condition ────────────────────────────────────────────────────────
    public static function getWearCondition(float $floatValue): array {
        if ($floatValue < 0.07) return ['Factory New',    1.00];
        if ($floatValue < 0.15) return ['Minimal Wear',   0.90];
        if ($floatValue < 0.38) return ['Field-Tested',   0.75];
        if ($floatValue < 0.45) return ['Well-Worn',      0.60];
        return                          ['Battle-Scarred', 0.45];
    }
}

