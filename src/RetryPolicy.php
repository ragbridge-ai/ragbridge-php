<?php

declare(strict_types=1);

namespace Ragbridge;

use InvalidArgumentException;

/**
 * Decides which failed requests are sent again, and how long to wait before that.
 *
 * Retries are off unless a policy is given to the client. A request is retried when it
 * failed for a reason that is likely to pass:
 *
 * - the request could not be sent or no response arrived (a transport error), and
 * - the service answered 429, 502, 503 or 504.
 *
 * Other 4xx responses, such as validation and authentication errors, and 500 responses are
 * never retried: sending the same request again gives the same answer.
 *
 * Only idempotent methods (GET, PUT and DELETE among the ones the client uses) are retried by
 * default. A POST is sent again only when $retryPost is set, because the service may have
 * processed a request whose response was lost.
 *
 * The wait grows exponentially from the base delay up to the maximum, and is randomised
 * (half of it is fixed, half is random) so that many clients do not retry in step. When the
 * service sends a Retry-After header, its value is used as it is. A Retry-After that is
 * longer than the maximum delay is not waited for: the failure is reported instead.
 */
final readonly class RetryPolicy
{
    /** Methods that can be repeated without changing the result (RFC 9110, section 9.2.2). */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    private const RETRYABLE_STATUSES = [429, 502, 503, 504];

    /**
     * @param int $maxAttempts total number of tries including the first, so 3 means up to 2 retries
     * @param int $baseDelayMs wait before the first retry, in milliseconds; doubled for each further retry
     * @param int $maxDelayMs longest wait between two tries, in milliseconds
     * @param bool $retryPost also retry POST requests, which include queries, searches and uploads
     *
     * @throws InvalidArgumentException when a value is out of range
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 10_000,
        public bool $retryPost = false,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('The maximum number of attempts must be at least 1, %d given.', $maxAttempts));
        }

        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException(sprintf('The base delay cannot be negative, %d given.', $baseDelayMs));
        }

        if ($maxDelayMs < $baseDelayMs) {
            throw new InvalidArgumentException(sprintf(
                'The maximum delay (%d ms) cannot be shorter than the base delay (%d ms).',
                $maxDelayMs,
                $baseDelayMs,
            ));
        }
    }

    /**
     * Whether a request with this HTTP method may be sent again.
     */
    public function appliesTo(string $method): bool
    {
        $method = strtoupper($method);

        return in_array($method, self::IDEMPOTENT_METHODS, true) || ($this->retryPost && $method === 'POST');
    }

    public function isRetryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Milliseconds to wait before the next try.
     *
     * @param int $failedAttempt number of the try that just failed, starting at 1
     * @param int|null $retryAfterMs wait requested by the service in a Retry-After header
     *
     * @return int|null null when the service asks for a wait longer than the maximum delay,
     *                  which means that the request should not be retried
     */
    public function delayMs(int $failedAttempt, ?int $retryAfterMs = null): ?int
    {
        if ($retryAfterMs !== null) {
            return $retryAfterMs <= $this->maxDelayMs ? $retryAfterMs : null;
        }

        // Doubling step by step stops at the maximum. The comparison is made before doubling,
        // so the arithmetic stays in integers and cannot overflow into a float.
        $ceiling = $this->baseDelayMs;

        for ($try = 1; $try < $failedAttempt && $ceiling < $this->maxDelayMs; $try++) {
            $ceiling = $ceiling > intdiv($this->maxDelayMs, 2) ? $this->maxDelayMs : $ceiling * 2;
        }

        $fixed = intdiv($ceiling, 2);

        return $fixed + random_int(0, $ceiling - $fixed);
    }
}
