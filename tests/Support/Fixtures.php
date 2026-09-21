<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Loads the JSON fixtures in tests/Fixtures and turns them into PSR-7 responses.
 */
final class Fixtures
{
    public static function json(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json');

        if ($contents === false) {
            throw new RuntimeException(sprintf('Fixture "%s" does not exist.', $name));
        }

        return $contents;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function response(int $status, string $body = '', array $headers = ['Content-Type' => 'application/json']): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }

    public static function jsonResponse(int $status, string $fixture): ResponseInterface
    {
        return self::response($status, self::json($fixture));
    }

    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}
