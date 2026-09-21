<?php

declare(strict_types=1);

namespace Ragbridge\Internal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the value of a Retry-After header, which is either a number of seconds or an HTTP
 * date (RFC 9110, section 10.2.3).
 *
 * @internal
 */
final class RetryAfter
{
    /**
     * @param int|null $now current Unix time, for tests; the real time when null
     *
     * @return int|null the wait in milliseconds, null when the value is empty or not understood
     */
    public static function milliseconds(string $value, ?int $now = null): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            // Nine digits are about 31 years: far beyond any delay worth waiting for, and no overflow.
            return strlen($value) > 9 ? PHP_INT_MAX : (int) $value * 1000;
        }

        $date = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));

        // An impossible date such as 32 Sep is not rejected but rolled over, with a warning.
        $problems = DateTimeImmutable::getLastErrors();

        if ($date === false || ($problems !== false && $problems['warning_count'] + $problems['error_count'] > 0)) {
            return null;
        }

        return max(0, $date->getTimestamp() - ($now ?? time())) * 1000;
    }
}
