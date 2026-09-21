<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Dto\SearchHit;
use Ragbridge\Dto\Source;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\SearchMode;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Integration;
use Ragbridge\Tests\Support\Thrown;

// These tests talk to a running ragbridge service. They are skipped unless
// RAGBRIDGE_INTEGRATION_URL and RAGBRIDGE_INTEGRATION_API_KEY are set, see tests/Integration/start.sh.

it('rejects an invalid API key', function (): void {
    $e = Thrown::by(fn() => Integration::client('rb_not-a-real-key')->documents(), AuthenticationException::class);

    expect($e->statusCode())->toBe(401);
});

it('lists documents', function (): void {
    $documents = Integration::client()->documents();

    // Every entry is a Document: the client rejects a response that does not match the schema.
    expect($documents)->toBeArray();
});

it('reports an unknown document as not found', function (): void {
    $client = Integration::client();
    $id = '00000000-0000-4000-8000-000000000000';

    $fetched = Thrown::by(fn() => $client->document($id), NotFoundException::class);
    $deleted = Thrown::by(fn() => $client->deleteDocument($id), NotFoundException::class);

    expect($fetched->statusCode())->toBe(404)
        ->and($deleted->statusCode())->toBe(404);
});

it('rejects an invalid query with the fields that are wrong', function (): void {
    $e = Thrown::by(fn() => Integration::client()->query('anything', topK: 0), ValidationException::class);

    $locations = array_map(static fn(array $error): string => implode('.', $error['loc']), $e->errors());

    expect($e->statusCode())->toBe(422)
        ->and($locations)->toContain('body.top_k');
});

it('rejects an unsupported file type', function (): void {
    $stream = Fixtures::factory()->createStream('not a supported document');

    $e = Thrown::by(fn() => Integration::client()->uploadStream($stream, 'data.bin'), RequestFailedException::class);

    expect($e->statusCode())->toBe(415);
});

it('uploads, lists, fetches and deletes a document', function (): void {
    $client = Integration::client();
    $token = Integration::token();
    $stream = Fixtures::factory()->createStream("The launch code for project {$token} is falcon-seven.\n");

    $uploaded = $client->uploadStream($stream, "notes-{$token}.txt");

    try {
        $document = Integration::waitUntilProcessed($client, $uploaded);

        expect($document->status)->toBe(DocumentStatus::Ready)
            ->and($document->filename)->toBe("notes-{$token}.txt")
            ->and($document->contentType)->toBe('text/plain')
            ->and($document->error)->toBeNull();

        $ids = array_map(static fn(Document $listed): string => $listed->id, $client->documents());

        expect($ids)->toContain($document->id)
            ->and($client->document($document->id)->id)->toBe($document->id);

        // Uploading the same content again returns the existing document.
        $again = $client->uploadStream(Fixtures::factory()->createStream("The launch code for project {$token} is falcon-seven.\n"), "notes-{$token}.txt");

        expect($again->id)->toBe($document->id);
    } finally {
        $client->deleteDocument($uploaded->id);
    }

    expect(fn() => $client->document($uploaded->id))->toThrow(NotFoundException::class);
});

it('uploads a file from disk', function (): void {
    $client = Integration::client();
    $token = Integration::token();
    $path = tempnam(sys_get_temp_dir(), 'ragbridge-');
    assert($path !== false);
    file_put_contents($path, "# Notes {$token}\n\nThe office is closed on the first Monday of March.\n");

    try {
        $uploaded = $client->upload($path, "notes-{$token}.md");

        try {
            $document = Integration::waitUntilProcessed($client, $uploaded);

            expect($document->status)->toBe(DocumentStatus::Ready)
                ->and($document->contentType)->toBe('text/markdown');
        } finally {
            $client->deleteDocument($uploaded->id);
        }
    } finally {
        unlink($path);
    }
});

it('answers a question with the sources it used', function (SearchMode $mode): void {
    $client = Integration::client();
    $token = Integration::token();
    $stream = Fixtures::factory()->createStream("The access phrase for the {$token} archive is copper-lantern.\n");

    $uploaded = $client->uploadStream($stream, "archive-{$token}.txt");

    try {
        Integration::waitUntilProcessed($client, $uploaded);

        $result = $client->query("What is the access phrase for the {$token} archive?", topK: 3, mode: $mode, explain: true);

        $ours = array_values(array_filter(
            $result->sources,
            static fn(Source $source): bool => $source->documentId === $uploaded->id,
        ));

        expect($result->answer)->not->toBe('')
            ->and($ours)->not->toBeEmpty();

        $source = $ours[0];

        expect($source->filename)->toBe("archive-{$token}.txt")
            ->and($source->snippet)->toContain($token)
            ->and($source->chunkIndex)->toBeInt()
            ->and($source->score)->toBeFloat()
            ->and($source->retrieval)->not->toBeNull();
    } finally {
        $client->deleteDocument($uploaded->id);
    }
})->with([
    'hybrid' => [SearchMode::Hybrid],
    'vector' => [SearchMode::Vector],
    'keyword' => [SearchMode::Keyword],
]);

it('searches without generating an answer', function (SearchMode $mode): void {
    $client = Integration::client();
    $token = Integration::token();
    $stream = Fixtures::factory()->createStream("The access phrase for the {$token} vault is amber-compass.\n");

    $uploaded = $client->uploadStream($stream, "vault-{$token}.txt");

    try {
        Integration::waitUntilProcessed($client, $uploaded);

        $result = $client->search("access phrase for the {$token} vault", topK: 3, mode: $mode, explain: true);

        $ours = array_values(array_filter(
            $result->results,
            static fn(SearchHit $hit): bool => $hit->documentId === $uploaded->id,
        ));

        expect($ours)->not->toBeEmpty()
            ->and($result->candidateCount)->toBeInt();

        $hit = $ours[0];

        expect($hit->filename)->toBe("vault-{$token}.txt")
            ->and($hit->content)->toContain('amber-compass')
            ->and($hit->score)->toBeFloat()
            ->and($hit->retrieval)->not->toBeNull();
    } finally {
        $client->deleteDocument($uploaded->id);
    }
})->with([
    'hybrid' => [SearchMode::Hybrid],
    'vector' => [SearchMode::Vector],
    'keyword' => [SearchMode::Keyword],
]);

it('rejects an invalid search with the fields that are wrong', function (): void {
    $e = Thrown::by(fn() => Integration::client()->search('anything', topK: 0), ValidationException::class);

    $locations = array_map(static fn(array $error): string => implode('.', $error['loc']), $e->errors());

    expect($locations)->toContain('body.top_k');
});
