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
     * Text of about $bytes bytes in which every paragraph carries the marker, so that a
     * search for the marker finds it. It is large enough for the service to process it in
     * the background when $bytes is above the service's threshold, 100000 by default.
     */
    public static function largeText(string $marker, int $bytes = 150_000): string
    {
        $paragraph = "The manual {$marker} describes the maintenance schedule of the boiler house in some detail.\n";

        return str_repeat($paragraph, (int) ceil($bytes / strlen($paragraph)));
    }

    /**
     * Waits until a document that is saved by its external id has been processed, and sends
     * the record again when the service reports that it failed for a reason that has nothing
     * to do with the record.
     *
     * A failed document is retried by sending the same record again, which is the documented
     * recovery. A local embedding model that is given several large texts at the same time can
     * reset a connection, and the service then reports the error of the model on the document.
     * That is a reason to send the record again. The error of a worker that lost track of the
     * text it was to process ("no raw_content") is not: it is what a bug of the service looks
     * like, and it is returned at once.
     *
     * @param int $attempts how many times to wait for the document, so at most attempts - 1 records are sent again
     */
    public static function settle(RagbridgeClient $client, string $externalId, string $title, string $text, Document $document, int $attempts = 4): Document
    {
        for ($attempt = 1; ; $attempt++) {
            $document = $client->waitUntilProcessed($document, 120);

            if ($document->status !== DocumentStatus::Failed || $attempt >= $attempts) {
                return $document;
            }

            if (str_contains((string) $document->error, 'raw_content')) {
                return $document;
            }

            // Not silent: the error of the service is the finding, so it is shown.
            fwrite(STDERR, sprintf("\n[integration] %s was reported as failed (attempt %d of %d): %s\n", $externalId, $attempt, $attempts, (string) $document->error));

            $document = $client->putDocument($externalId, $title, $text)->document;
        }
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
