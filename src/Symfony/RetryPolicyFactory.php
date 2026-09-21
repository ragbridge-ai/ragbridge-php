<?php

declare(strict_types=1);

namespace Ragbridge\Symfony;

use Ragbridge\RetryPolicy;

/**
 * Builds the retry policy of the bundle from its configuration.
 *
 * Every option can be an environment variable, whose value is only known when the service is
 * created. So whether retries are on is decided here, at run time, and not while the
 * container is compiled.
 *
 * @internal
 */
final class RetryPolicyFactory
{
    /**
     * @return RetryPolicy|null null when retries are not enabled
     */
    public static function create(bool $enabled, int $maxAttempts, int $baseDelayMs, int $maxDelayMs, bool $retryPost): ?RetryPolicy
    {
        return $enabled ? new RetryPolicy($maxAttempts, $baseDelayMs, $maxDelayMs, $retryPost) : null;
    }
}
