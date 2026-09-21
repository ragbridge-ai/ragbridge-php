<?php

declare(strict_types=1);

namespace Ragbridge\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Typed accessor over a decoded JSON object.
 *
 * Every accessor verifies the type of the field it returns and throws an
 * InvalidArgumentException that names the context and the field otherwise, so a response
 * that does not match the expected shape fails at the boundary.
 *
 * @internal
 */
final readonly class Payload
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array<mixed> $data decoded JSON object
     * @param string $context name used as the prefix of error messages
     */
    public function __construct(
        private array $data,
        private string $context,
    ) {}

    public function invalid(string $message): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('%s: %s', $this->context, $message));
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        return is_string($value) ? $value : throw $this->wrongType($key, 'a string', $value);
    }

    /**
     * A missing key is treated like an explicit null.
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->optional($key);

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw $this->wrongType($key, 'a string or null', $value),
        };
    }

    public function uuid(string $key): string
    {
        $value = $this->string($key);

        return preg_match(self::UUID_PATTERN, $value) === 1
            ? $value
            : throw $this->invalid(sprintf('field "%s" must be a UUID, "%s" given', $key, $value));
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        return is_int($value) ? $value : throw $this->wrongType($key, 'an integer', $value);
    }

    /**
     * A missing key is treated like an explicit null.
     */
    public function nullableInt(string $key): ?int
    {
        $value = $this->optional($key);

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            default => throw $this->wrongType($key, 'an integer or null', $value),
        };
    }

    /**
     * JSON does not distinguish 1 from 1.0, so integers are accepted and widened.
     */
    public function float(string $key): float
    {
        $value = $this->required($key);

        return match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            default => throw $this->wrongType($key, 'a number', $value),
        };
    }

    /**
     * A missing key or an explicit null yields the default.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->optional($key);

        return match (true) {
            $value === null => $default,
            is_bool($value) => $value,
            default => throw $this->wrongType($key, 'a boolean', $value),
        };
    }

    /**
     * Parses an ISO 8601 timestamp. A timestamp without an offset is read as UTC.
     */
    public function dateTime(string $key): DateTimeImmutable
    {
        return $this->parseDateTime($key, $this->string($key));
    }

    /**
     * A missing key is treated like an explicit null.
     */
    public function nullableDateTime(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return $value === null ? null : $this->parseDateTime($key, $value);
    }

    /**
     * A JSON object with arbitrary keys, such as user metadata.
     *
     * An empty JSON object decodes to an empty PHP array, which is indistinguishable from an
     * empty list, so an empty array is accepted here. A non-empty list is not an object. A
     * missing key or an explicit null yields an empty array.
     *
     * @return array<array-key, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->optional($key);

        return match (true) {
            $value === null => [],
            is_array($value) && ($value === [] || ! array_is_list($value)) => $value,
            default => throw $this->wrongType($key, 'an object', $value),
        };
    }

    /**
     * @return array<mixed>|null null when the key is missing or null
     */
    public function nullableObject(string $key): ?array
    {
        $value = $this->optional($key);

        return match (true) {
            $value === null => null,
            is_array($value) && ! array_is_list($value) => $value,
            default => throw $this->wrongType($key, 'an object or null', $value),
        };
    }

    /**
     * @return list<array<mixed>>
     */
    public function objects(string $key): array
    {
        $value = $this->required($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->wrongType($key, 'a list', $value);
        }

        $objects = [];

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                throw $this->invalid(sprintf(
                    'field "%s" item %d must be an object, %s given',
                    $key,
                    $index,
                    get_debug_type($item),
                ));
            }

            $objects[] = $item;
        }

        return $objects;
    }

    private function parseDateTime(string $key, string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            throw $this->invalid(sprintf('field "%s" must be an ISO 8601 timestamp, "%s" given', $key, $value));
        }
    }

    private function required(string $key): mixed
    {
        if (! array_key_exists($key, $this->data)) {
            throw $this->invalid(sprintf('missing required field "%s"', $key));
        }

        return $this->data[$key];
    }

    private function optional(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    private function wrongType(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return $this->invalid(sprintf('field "%s" must be %s, %s given', $key, $expected, get_debug_type($value)));
    }
}
