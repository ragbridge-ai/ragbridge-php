<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Dto\SearchHit;
use Ragbridge\Dto\Source;
use Ragbridge\Dto\SyncResult;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\RagbridgeClient;
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

it('answers with the agent and reports the steps it took', function (): void {
    $client = Integration::client();
    $token = Integration::token();
    $stream = Fixtures::factory()->createStream("The access phrase for the {$token} cellar is silver-anchor.\n");

    $uploaded = $client->uploadStream($stream, "cellar-{$token}.txt");

    try {
        Integration::waitUntilProcessed($client, $uploaded);

        $result = $client->agent("What is the access phrase for the {$token} cellar?", maxSteps: 2);

        $ours = array_filter(
            $result->sources,
            static fn(Source $source): bool => $source->documentId === $uploaded->id,
        );

        expect($result->answer)->not->toBe('')
            ->and($ours)->not->toBeEmpty()
            ->and($result->steps)->not->toBeEmpty()
            ->and($result->steps[0]->query)->toBeString()
            ->and($result->stepCount)->toBeGreaterThanOrEqual(1)
            ->and($result->stepCount)->toBeLessThanOrEqual(2);
    } finally {
        $client->deleteDocument($uploaded->id);
    }
});

it('rejects an invalid agent step limit', function (): void {
    $e = Thrown::by(fn() => Integration::client()->agent('anything', maxSteps: 0), ValidationException::class);

    $locations = array_map(static fn(array $error): string => implode('.', $error['loc']), $e->errors());

    expect($locations)->toContain('body.max_steps');
});

it('reports that the service is alive and ready', function (): void {
    $client = Integration::client();

    expect($client->health()->isOk())->toBeTrue()
        ->and($client->readiness()->isOk())->toBeTrue();
});

describe('documents identified by an external id', function (): void {
    it('creates, changes and replaces a document, keeping its identity', function (): void {
        $client = Integration::client();
        $id = 'article:' . Integration::token();
        $first = new DateTimeImmutable('2026-09-21T10:00:00.123456+00:00');

        try {
            $created = $client->putDocument($id, 'Refund policy', 'Refunds are possible within 14 days of purchase.', ['locale' => 'en'], $first);

            expect($created->result)->toBe(SyncResult::Created)
                ->and($created->statusCode)->toBe(201)
                ->and($created->document->externalId)->toBe($id)
                ->and($created->document->filename)->toBe('Refund policy')
                ->and($created->document->contentType)->toBe('text/plain')
                ->and($created->document->status)->toBe(DocumentStatus::Ready)
                ->and($created->document->metadata)->toBe(['locale' => 'en'])
                ->and($created->document->sourceUpdatedAt?->format('Y-m-d\TH:i:s.u'))->toBe('2026-09-21T10:00:00.123456')
                ->and($created->document->updatedAt)->not->toBeNull();

            $documentId = $created->document->id;

            // The same record again: nothing is written.
            $again = $client->putDocument($id, 'Refund policy', 'Refunds are possible within 14 days of purchase.', ['locale' => 'en'], $first);

            expect($again->result)->toBe(SyncResult::Unchanged)
                ->and($again->statusCode)->toBe(200)
                ->and($again->document->id)->toBe($documentId);

            // Only the title changed: no new embedding, same document.
            $renamed = $client->putDocument($id, 'Returns policy', 'Refunds are possible within 14 days of purchase.', ['locale' => 'en'], $first);

            expect($renamed->result)->toBe(SyncResult::Updated)
                ->and($renamed->document->id)->toBe($documentId)
                ->and($renamed->document->filename)->toBe('Returns policy');

            // Only the metadata changed.
            $tagged = $client->putDocument($id, 'Returns policy', 'Refunds are possible within 14 days of purchase.', ['locale' => 'de'], $first);

            expect($tagged->result)->toBe(SyncResult::Updated)
                ->and($tagged->document->metadata)->toBe(['locale' => 'de']);

            // New text: replaced, and still the same document.
            $replaced = $client->putDocument($id, 'Returns policy', 'Refunds are possible within 30 days of purchase.', ['locale' => 'de'], new DateTimeImmutable('2026-09-21T11:00:00+00:00'));

            expect($replaced->result)->toBe(SyncResult::Replaced)
                ->and($replaced->statusCode)->toBe(200)
                ->and($replaced->document->id)->toBe($documentId)
                ->and($replaced->document->sourceUpdatedAt?->format('H:i'))->toBe('11:00');

            // An older record is ignored, and the document is as it was.
            $stale = $client->putDocument($id, 'Old title', 'Refunds are impossible.', ['locale' => 'xx'], new DateTimeImmutable('2026-09-21T09:00:00+00:00'));

            expect($stale->result)->toBe(SyncResult::Stale)
                ->and($stale->statusCode)->toBe(200)
                ->and($stale->document->id)->toBe($documentId)
                ->and($stale->document->filename)->toBe('Returns policy')
                ->and($stale->document->metadata)->toBe(['locale' => 'de']);

            $fetched = $client->getByExternalId($id);

            expect($fetched->id)->toBe($documentId)
                ->and($fetched->filename)->toBe('Returns policy');

            // It is listed and fetched by its UUID like any other document.
            $listed = array_values(array_filter($client->documents(), static fn(Document $document): bool => $document->id === $documentId));

            expect($listed)->toHaveCount(1)
                ->and($listed[0]->externalId)->toBe($id)
                ->and($client->document($documentId)->externalId)->toBe($id);
        } finally {
            $client->deleteByExternalId($id);
        }
    });

    it('is found by a question, with its title as the file name', function (): void {
        $client = Integration::client();
        $token = Integration::token();
        $id = "kb:{$token}";

        try {
            $client->putDocument($id, "Vault {$token}", "The access phrase for the {$token} vault is amber-compass.");

            $result = $client->search("access phrase for the {$token} vault", topK: 3);

            $ours = array_values(array_filter($result->results, static fn(SearchHit $hit): bool => $hit->filename === "Vault {$token}"));

            expect($ours)->not->toBeEmpty()
                ->and($ours[0]->content)->toContain('amber-compass');

            // The old text is gone once the record is replaced.
            $client->putDocument($id, "Vault {$token}", "The access phrase for the {$token} vault is silver-anchor.");

            $after = $client->search("access phrase for the {$token} vault", topK: 3);
            $contents = implode("\n", array_map(static fn(SearchHit $hit): string => $hit->content, $after->results));

            expect($contents)->toContain('silver-anchor')
                ->and($contents)->not->toContain('amber-compass');
        } finally {
            $client->deleteByExternalId($id);
        }
    });

    it('deletes without failing when the document does not exist', function (): void {
        $client = Integration::client();
        $id = 'gone:' . Integration::token();

        $client->putDocument($id, 'Temporary', 'A record that is about to be deleted.');

        $client->deleteByExternalId($id);
        $client->deleteByExternalId($id);
        $client->deleteByExternalId('never-existed:' . Integration::token());

        expect(fn() => $client->getByExternalId($id))->toThrow(NotFoundException::class);
    });

    it('reports an unknown external id as not found', function (): void {
        $e = Thrown::by(fn() => Integration::client()->getByExternalId('missing:' . Integration::token()), NotFoundException::class);

        expect($e->statusCode())->toBe(404);
    });

    it('accepts every character an id may contain, and ids that need encoding', function (string $id): void {
        $client = Integration::client();
        $id .= Integration::token();

        try {
            $put = $client->putDocument($id, 'Record', "Text of {$id}");

            expect($put->document->externalId)->toBe($id)
                ->and($client->getByExternalId($id)->id)->toBe($put->document->id);
        } finally {
            $client->deleteByExternalId($id);
        }

        expect(fn() => $client->getByExternalId($id))->toThrow(NotFoundException::class);
    })->with([
        'with a colon' => ['article:'],
        'with an at sign' => ['user@example.'],
        'with dots, underscores and dashes' => ['wp_posts.42-a_'],
        'in upper case' => ['UPPER:Case:'],
        'a single character' => ['q'],
    ]);

    it('keeps ids that differ only in case apart', function (): void {
        $client = Integration::client();
        $token = Integration::token();

        try {
            $lower = $client->putDocument("case:{$token}", 'Lower', 'The lower case record.');
            $upper = $client->putDocument('CASE:' . $token, 'Upper', 'The upper case record.');

            expect($lower->result)->toBe(SyncResult::Created)
                ->and($upper->result)->toBe(SyncResult::Created)
                ->and($upper->document->id)->not->toBe($lower->document->id);
        } finally {
            $client->deleteByExternalId("case:{$token}");
            $client->deleteByExternalId('CASE:' . $token);
        }
    });

    it('rejects an id the service does not allow as a validation error that names the id', function (string $id): void {
        $client = Integration::client();

        $put = Thrown::by(fn() => $client->putDocument($id, 'Record', 'Some text.'), ValidationException::class);
        $get = Thrown::by(fn() => $client->getByExternalId($id), ValidationException::class);
        $delete = Thrown::by(fn() => $client->deleteByExternalId($id), ValidationException::class);

        foreach ([$put, $get, $delete] as $e) {
            $locations = array_map(static fn(array $error): string => implode('.', $error['loc']), $e->errors());

            expect($e->statusCode())->toBe(422)
                ->and($locations)->toContain('path.external_id');
        }
    })->with([
        'a leading dash' => ['-bad'],
        'a leading dot' => ['.hidden'],
        'a space' => ['has space'],
        'a plus sign' => ['a+b'],
        'a non-ASCII letter' => ['café'],
        'a percent sign' => ['100%'],
        'too long' => [str_repeat('a', 256)],
    ]);

    it('refuses an id with a slash before it reaches the service', function (): void {
        $client = Integration::client();

        expect(fn() => $client->putDocument('a/b', 'Record', 'Some text.'))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $client->deleteByExternalId('a/b'))->toThrow(InvalidArgumentException::class);
    });

    it('rejects values the service does not allow as validation errors on the right field', function (Closure $call, string $field): void {
        $e = Thrown::by(fn() => $call(Integration::client(), 'inv:' . Integration::token()), ValidationException::class);

        $locations = array_map(static fn(array $error): string => implode('.', $error['loc']), $e->errors());

        expect($locations)->toContain($field);
    })->with([
        'an empty title' => [fn(RagbridgeClient $client, string $id) => $client->putDocument($id, '', 'Some text.'), 'body.title'],
        'a title over 500 characters' => [fn(RagbridgeClient $client, string $id) => $client->putDocument($id, str_repeat('t', 501), 'Some text.'), 'body.title'],
        'blank content' => [fn(RagbridgeClient $client, string $id) => $client->putDocument($id, 'Title', "  \n\t "), 'body.content'],
    ]);

    it('sends metadata that is empty, missing or nested without a validation error', function (): void {
        $client = Integration::client();
        $id = 'meta:' . Integration::token();
        $nested = ['locale' => 'en', 'tags' => ['a', 'b'], 'author' => ['id' => 7, 'name' => 'Ada'], 'draft' => false, 'rating' => 4.5, 'note' => null];

        try {
            // Missing and empty metadata are both an empty JSON object for the service.
            $created = $client->putDocument($id, 'Record', 'Some text.');
            $emptyArray = $client->putDocument($id, 'Record', 'Some text.', []);

            expect($created->document->metadata)->toBe([])
                ->and($emptyArray->result)->toBe(SyncResult::Unchanged)
                ->and($emptyArray->document->metadata)->toBe([]);

            $nestedResult = $client->putDocument($id, 'Record', 'Some text.', $nested);

            // The service stores metadata as JSONB, which does not keep the order of the keys.
            expect($nestedResult->result)->toBe(SyncResult::Updated)
                ->and($nestedResult->document->metadata)->toEqual($nested)
                ->and($client->getByExternalId($id)->metadata)->toEqual($nested);

            // Metadata that is not sent is removed: the record's state is sent every time.
            $cleared = $client->putDocument($id, 'Record', 'Some text.');

            expect($cleared->result)->toBe(SyncResult::Updated)
                ->and($cleared->document->metadata)->toBe([]);
        } finally {
            $client->deleteByExternalId($id);
        }
    });

    it('rejects metadata that is a list before it reaches the service', function (): void {
        expect(fn() => Integration::client()->putDocument('meta:list', 'Record', 'Some text.', ['a', 'b']))
            ->toThrow(InvalidArgumentException::class, 'metadata must be a map');
    });

    it('rejects metadata over 16 KB as a validation error', function (): void {
        $e = Thrown::by(
            fn() => Integration::client()->putDocument('meta:big:' . Integration::token(), 'Record', 'Some text.', ['blob' => str_repeat('x', 17 * 1024)]),
            ValidationException::class,
        );

        expect($e->statusCode())->toBe(422);
    });

    it('rejects a time without a time zone as a validation error, and one with a zone is accepted', function (): void {
        $client = Integration::client();
        $id = 'time:' . Integration::token();

        try {
            // A PHP date time always has a zone, so a naive time has to be sent by hand.
            $http = Fixtures::factory();
            $request = $http->createRequest('PUT', rtrim((string) getenv('RAGBRIDGE_INTEGRATION_URL'), '/') . '/documents/external/' . rawurlencode($id))
                ->withHeader('Authorization', 'Bearer ' . getenv('RAGBRIDGE_INTEGRATION_API_KEY'))
                ->withHeader('Content-Type', 'application/json')
                ->withBody($http->createStream(json_encode(['title' => 'T', 'content' => 'Some text.', 'source_updated_at' => '2026-09-21T10:00:00'], JSON_THROW_ON_ERROR)));
            $status = (new Symfony\Component\HttpClient\Psr18Client())->sendRequest($request)->getStatusCode();

            expect($status)->toBe(422);

            expect($client->putDocument($id, 'T', 'Some text.', sourceUpdatedAt: new DateTimeImmutable('2026-09-21T10:00:00+05:30'))->result)
                ->toBe(SyncResult::Created);
        } finally {
            $client->deleteByExternalId($id);
        }
    });

    it('queues large text, and it is ready once the worker has processed it', function (): void {
        $client = Integration::client();
        $token = Integration::token();
        $id = "large:{$token}";
        $paragraph = "The {$token} manual describes the maintenance schedule of the boiler house in some detail. ";
        $text = str_repeat($paragraph, (int) ceil(150_000 / strlen($paragraph)));

        try {
            $created = $client->putDocument($id, "Manual {$token}", $text);

            expect(strlen($text))->toBeGreaterThan(140_000)
                ->and($created->statusCode)->toBe(202)
                ->and($created->isQueued())->toBeTrue()
                ->and($created->result)->toBe(SyncResult::Created)
                ->and($created->document->status)->toBe(DocumentStatus::Pending);

            $document = Integration::waitUntilProcessed($client, $created->document);

            // The error first, so that a failure says what the service reported.
            expect($document->error)->toBeNull()
                ->and($document->status)->toBe(DocumentStatus::Ready)
                ->and($document->id)->toBe($created->document->id)
                ->and($client->getByExternalId($id)->status)->toBe(DocumentStatus::Ready);

            // Sending the same text again changes nothing and is not queued again.
            $again = $client->putDocument($id, "Manual {$token}", $text);

            expect($again->result)->toBe(SyncResult::Unchanged)
                ->and($again->statusCode)->toBe(200);

            // A change of the large text is queued too, and keeps the document.
            $replaced = $client->putDocument($id, "Manual {$token}", $text . "\nAn addition about the {$token} chimney.");

            expect($replaced->result)->toBe(SyncResult::Replaced)
                ->and($replaced->isQueued())->toBeTrue()
                ->and($replaced->document->id)->toBe($created->document->id);

            expect(Integration::waitUntilProcessed($client, $replaced->document)->status)->toBe(DocumentStatus::Ready);
        } finally {
            $client->deleteByExternalId($id);
        }
    });

    it('does not disturb uploading a file', function (): void {
        $client = Integration::client();
        $token = Integration::token();
        $id = "sync:{$token}";
        $stream = Fixtures::factory()->createStream("The upload {$token} is a plain file.\n");

        try {
            $client->putDocument($id, "Record {$token}", "The record {$token} is a synced document.");
            $uploaded = $client->uploadStream($stream, "file-{$token}.txt");
            $document = Integration::waitUntilProcessed($client, $uploaded);

            expect($document->status)->toBe(DocumentStatus::Ready)
                ->and($document->externalId)->toBeNull()
                ->and($document->metadata)->toBe([])
                ->and($document->sourceUpdatedAt)->toBeNull()
                ->and($document->updatedAt)->not->toBeNull();

            // Uploading the same file again still returns the existing upload.
            $again = $client->uploadStream(Fixtures::factory()->createStream("The upload {$token} is a plain file.\n"), "file-{$token}.txt");

            expect($again->id)->toBe($uploaded->id);
        } finally {
            $client->deleteByExternalId($id);

            if (isset($uploaded)) {
                $client->deleteDocument($uploaded->id);
            }
        }
    });

    it('stores two records with the same text, and deleting one leaves the other', function (): void {
        $client = Integration::client();
        $token = Integration::token();
        $text = "Both products share the description {$token}.";

        try {
            $one = $client->putDocument("product:{$token}:1", 'Product one', $text);
            $two = $client->putDocument("product:{$token}:2", 'Product two', $text);

            expect($one->result)->toBe(SyncResult::Created)
                ->and($two->result)->toBe(SyncResult::Created)
                ->and($two->document->id)->not->toBe($one->document->id);

            $client->deleteByExternalId("product:{$token}:1");

            expect($client->getByExternalId("product:{$token}:2")->id)->toBe($two->document->id);
        } finally {
            $client->deleteByExternalId("product:{$token}:1");
            $client->deleteByExternalId("product:{$token}:2");
        }
    });
});
