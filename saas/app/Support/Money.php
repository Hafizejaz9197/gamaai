<?php

namespace App\Support;

final class Money
{
    /** Format millionths of a US dollar, e.g. 12345 → "$0.0123". */
    public static function usd(?int $micros): string
    {
        if ($micros === null) {
            return '—';
        }

        return ($micros < 0 ? '-$' : '$').number_format(abs($micros) / 1_000_000, 4);
    }
}
