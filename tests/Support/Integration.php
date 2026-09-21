<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\RagbridgeClient;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Helpers for the integration tests, which run against a live ragbridge service.
 *
 * The service is described by the environment variables RAGBRIDGE_INTEGRATION_URL and
 * RAGBRIDGE_INTEGRATION_API_KEY. tests/Integration/start.sh starts one and prints them.
 */
final class Integration
{
    public static function isConfigured(): bool
    {
        return self::env('RAGBRIDGE_INTEGRATION_URL') !== null && self::env('RAGBRIDGE_INTEGRATION_API_KEY') !== null;
    }

    public static function client(?string $apiKey = null): RagbridgeClient
    {
        // Answering runs a language model, which is slow on a machine without a GPU.
        $http = new Psr18Client(HttpClient::create(['timeout' => 120]));

        return new RagbridgeClient(
            $http,
            $http,
            $http,
            self::env('RAGBRIDGE_INTEGRATION_URL') ?? throw new RuntimeException('RAGBRIDGE_INTEGRATION_URL is not set.'),
            $apiKey ?? self::env('RAGBRIDGE_INTEGRATION_API_KEY'),
        );
    }

    /**
     * A random token that makes an uploaded text unique, so that repeated runs against the
     * same service do not hit its detection of duplicate content.
     */
    public static function token(): string
    {
        return 'zq' . bin2hex(random_bytes(6));
    }

    /**
     * Waits until the service has finished processing an upload.
     */
    public static function waitUntilProcessed(RagbridgeClient $client, Document $document, int $timeoutSeconds = 90): Document
    {
        $deadline = time() + $timeoutSeconds;

        while (in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
            if (time() >= $deadline) {
                throw new RuntimeException(sprintf('Document %s was still %s after %d seconds.', $document->id, $document->status->value, $timeoutSeconds));
            }

            sleep(1);
            $document = $client->document($document->id);
        }

        return $document;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
