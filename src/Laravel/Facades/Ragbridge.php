<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ragbridge\RagbridgeClient;

/**
 * Facade for the ragbridge client.
 *
 * @method static \Ragbridge\Dto\QueryResult query(string $question, int $topK = 5, ?\Ragbridge\SearchMode $mode = null, bool $explain = false)
 * @method static \Ragbridge\Dto\SearchResult search(string $query, int $topK = 5, ?\Ragbridge\SearchMode $mode = null, bool $explain = false)
 * @method static \Ragbridge\Dto\AgentResult agent(string $question, ?int $maxSteps = null)
 * @method static \Ragbridge\Dto\Document upload(string $path, ?string $filename = null, ?string $contentType = null)
 * @method static \Ragbridge\Dto\Document uploadStream(\Psr\Http\Message\StreamInterface $stream, string $filename, ?string $contentType = null)
 * @method static list<\Ragbridge\Dto\Document> documents()
 * @method static \Ragbridge\Dto\Document document(string $id)
 * @method static void deleteDocument(string $id)
 * @method static \Ragbridge\Dto\HealthStatus health()
 * @method static \Ragbridge\Dto\HealthStatus readiness()
 *
 * @see RagbridgeClient
 */
final class Ragbridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RagbridgeClient::class;
    }
}
