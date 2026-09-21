<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Builders for valid decoded API payloads. Tests override or remove single fields to
 * exercise the failure cases.
 */
final class Payloads
{
    public const DOCUMENT_ID = '3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function document(array $overrides = []): array
    {
        return [
            'id' => self::DOCUMENT_ID,
            'filename' => 'handbook.pdf',
            'content_type' => 'application/pdf',
            'status' => 'ready',
            'error' => null,
            'created_at' => '2026-03-14T09:26:53.589793Z',
            ...$overrides,
        ];
    }

    /**
     * Decodes JSON the way the client does, so that an empty JSON object becomes an empty array.
     *
     * @return array<mixed>
     */
    public static function fromJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new RuntimeException('The JSON is not an object.');
    }

    /**
     * A document as a service with external ids sends it. For an upload, external_id and
     * source_updated_at are null and metadata is empty.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function externalDocument(array $overrides = []): array
    {
        return self::document([
            'external_id' => 'article:42',
            'filename' => 'Refund policy',
            'content_type' => 'text/plain',
            'metadata' => ['locale' => 'en'],
            'source_updated_at' => '2026-09-21T10:00:00.123456Z',
            'updated_at' => '2026-09-21T10:05:00.500000Z',
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function retrieval(array $overrides = []): array
    {
        return [
            'vector_rank' => 2,
            'keyword_rank' => null,
            'fused_score' => 0.0328,
            'rank_before_rerank' => 4,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function source(array $overrides = []): array
    {
        return [
            'document_id' => self::DOCUMENT_ID,
            'filename' => 'handbook.pdf',
            'chunk_index' => 3,
            'snippet' => 'Employees accrue 25 days of leave per year.',
            'score' => 0.87,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function queryResult(array $overrides = []): array
    {
        return [
            'answer' => 'Employees receive 25 days of leave.',
            'sources' => [self::source()],
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function searchHit(array $overrides = []): array
    {
        return [
            'document_id' => self::DOCUMENT_ID,
            'filename' => 'handbook.pdf',
            'chunk_index' => 3,
            'content' => 'Employees accrue 25 days of leave per year.',
            'score' => 0.87,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function searchResult(array $overrides = []): array
    {
        return [
            'results' => [self::searchHit()],
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function agentStep(array $overrides = []): array
    {
        return [
            'query' => 'How much leave do employees get?',
            'results' => 4,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function agentResult(array $overrides = []): array
    {
        return [
            'answer' => 'Employees receive 25 days of leave.',
            'sources' => [self::source()],
            'steps' => [self::agentStep()],
            'step_count' => 1,
            ...$overrides,
        ];
    }

    /**
     * The body of a PUT to /documents/external/{id}.
     *
     * @param array<string, mixed> $document overrides for the document
     *
     * @return array<string, mixed>
     */
    public static function syncResult(string $result = 'created', array $document = []): array
    {
        return ['result' => $result, 'document' => self::externalDocument($document)];
    }

    /**
     * @param array<string, mixed> $document overrides for the document
     */
    public static function syncResponse(int $status, string $result = 'created', array $document = []): ResponseInterface
    {
        return Fixtures::response($status, json_encode(self::syncResult($result, $document), JSON_THROW_ON_ERROR));
    }
}
