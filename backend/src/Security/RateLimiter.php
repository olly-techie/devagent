<?php
declare(strict_types=1);

namespace DevAgent\Security;

/**
 * Sliding-window rate limiter backed by MySQL.
 *
 * Each "bucket" is identified by a string key (e.g. "user:42:task").
 * Within a rolling time window, it counts requests and rejects those
 * that exceed the configured maximum.
 *
 * The DB table is a simple counter with an atomic UPSERT on each hit.
 */
final class RateLimiter
{
    /**
     * Attempt to consume one slot in the rate limit bucket.
     *
     * @param string $key       Unique bucket key (e.g. "user:42:task")
     * @param int    $maxHits   Maximum allowed hits within the window
     * @param int    $windowSec Rolling window size in seconds
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public static function hit(string $key, int $maxHits, int $windowSec): array
    {
        $db  = db();
        $now = time();

        // Purge expired buckets opportunistically (1-in-20 chance per request)
        if (random_int(1, 20) === 1) {
            self::purgeExpired();
        }

        // Atomic upsert: insert or increment counter for this window
        $windowStart = date('Y-m-d H:i:s', $now - ($now % $windowSec));
        $windowEnd   = date('Y-m-d H:i:s', $now - ($now % $windowSec) + $windowSec);

        $db->prepare('
            INSERT INTO rate_limits (bucket_key, window_start, window_end, hit_count, last_hit_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                hit_count   = hit_count + 1,
                last_hit_at = NOW()
        ')->execute([$key, $windowStart, $windowEnd]);

        // Read current count
        $stmt = $db->prepare('
            SELECT hit_count FROM rate_limits
            WHERE bucket_key = ? AND window_start = ?
        ');
        $stmt->execute([$key, $windowStart]);
        $count = (int) ($stmt->fetchColumn() ?: 0);

        $remaining   = max(0, $maxHits - $count);
        $allowed     = $count <= $maxHits;
        $retryAfter  = $allowed ? 0 : (int) (strtotime($windowEnd) - $now);

        return [
            'allowed'      => $allowed,
            'remaining'    => $remaining,
            'retry_after'  => $retryAfter,
            'limit'        => $maxHits,
            'window_sec'   => $windowSec,
        ];
    }

    /**
     * Enforce rate limit or abort with 429.
     * Injects standard rate limit headers into the response.
     */
    public static function enforce(string $key, int $maxHits, int $windowSec): void
    {
        $result = self::hit($key, $maxHits, $windowSec);

        header("X-RateLimit-Limit: {$result['limit']}");
        header("X-RateLimit-Remaining: {$result['remaining']}");
        header("X-RateLimit-Window: {$result['window_sec']}");

        if (!$result['allowed']) {
            header("Retry-After: {$result['retry_after']}");
            jsonError(
                "Rate limit exceeded. Try again in {$result['retry_after']} seconds.",
                429
            );
        }
    }

    /**
     * Build a canonical bucket key from parts.
     */
    public static function key(string ...$parts): string
    {
        return implode(':', $parts);
    }

    private static function purgeExpired(): void
    {
        try {
            db()->exec("DELETE FROM rate_limits WHERE window_end < NOW()");
        } catch (\Throwable) {
            // Non-critical cleanup — ignore failures
        }
    }
}
