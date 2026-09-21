<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service rejected the request as invalid (HTTP 422).
 *
 * The field errors are available through errors(). Each entry describes one problem with
 * its location in the request (`loc`), a message (`msg`) and an error type (`type`).
 */
final class ValidationException extends ApiException
{
    /**
     * @return list<array{loc: list<int|string>, msg: string, type: string}>
     */
    public function errors(): array
    {
        return self::extractErrors($this->body());
    }

    /**
     * @return list<array{loc: list<int|string>, msg: string, type: string}>
     */
    private static function extractErrors(mixed $body): array
    {
        if (! is_array($body) || ! is_array($body['detail'] ?? null)) {
            return [];
        }

        $errors = [];

        foreach ($body['detail'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['msg'] ?? null) || ! is_string($entry['type'] ?? null)) {
                continue;
            }

            $errors[] = [
                'loc' => self::location($entry['loc'] ?? null),
                'msg' => $entry['msg'],
                'type' => $entry['type'],
            ];
        }

        return $errors;
    }

    protected static function describe(int $statusCode, mixed $body): string
    {
        $errors = self::extractErrors($body);

        if ($errors === []) {
            return parent::describe($statusCode, $body);
        }

        $problems = array_map(
            static fn(array $error): string => sprintf('%s: %s', implode('.', $error['loc']), $error['msg']),
            $errors,
        );

        return sprintf('ragbridge service rejected the request (HTTP %d): %s', $statusCode, implode('; ', $problems));
    }

    /**
     * @return list<int|string>
     */
    private static function location(mixed $loc): array
    {
        if (! is_array($loc)) {
            return [];
        }

        return array_values(array_filter($loc, static fn(mixed $part): bool => is_int($part) || is_string($part)));
    }
}
