<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * How long a provider asked us to wait after a rate limit (HTTP 429), in
 * whole seconds. Read from the standard Retry-After header (seconds or a
 * date), the x-ratelimit-reset-* headers some OpenAI-compatible providers
 * such as Groq send ("7.66s", "1m2.5s"), or the "try again in 5.2s" hint in
 * the error message, in that order.
 */
final class RetryAfter
{
    public static function seconds(?string $retryAfter, ?string $resetTokens = null, ?string $message = null): ?int
    {
        $retryAfter = trim((string) $retryAfter);
        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                return max(1, (int) ceil((float) $retryAfter));
            }
            try {
                return max(1, (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($retryAfter), false)));
            } catch (Throwable) {
                // Not a date either; try the other hints.
            }
        }

        if ($message !== null && preg_match('/try again in\s+((?:\d+(?:\.\d+)?(?:ms|h|m|s))+)/i', $message, $m)) {
            return self::duration($m[1]);
        }

        return trim((string) $resetTokens) !== '' ? self::duration(trim((string) $resetTokens)) : null;
    }

    /**
     * "1h2m3.5s", "7.66s" or "450ms" in whole seconds, rounded up.
     */
    private static function duration(string $text): ?int
    {
        if (! preg_match_all('/(\d+(?:\.\d+)?)(ms|h|m|s)/', $text, $parts, PREG_SET_ORDER)) {
            return null;
        }
        $seconds = 0.0;
        foreach ($parts as [, $number, $unit]) {
            $seconds += (float) $number * ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600][$unit];
        }

        return max(1, (int) ceil($seconds));
    }
}
