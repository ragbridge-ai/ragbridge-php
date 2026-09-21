<?php

declare(strict_types=1);

use Ragbridge\Internal\MultipartFile;
use Ragbridge\Tests\Support\Fixtures;

function boundaryOf(MultipartFile $multipart): string
{
    expect($multipart->contentType())->toMatch('/^multipart\/form-data; boundary=ragbridge-[0-9a-f]{32}$/');

    return substr($multipart->contentType(), strlen('multipart/form-data; boundary='));
}

it('builds a multipart body around the file content', function (): void {
    $factory = Fixtures::factory();

    $multipart = MultipartFile::create($factory, $factory->createStream('file bytes'), 'notes.txt', 'text/plain');
    $boundary = boundaryOf($multipart);

    expect((string) $multipart->body())->toBe(
        "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"notes.txt\"\r\n"
        . "Content-Type: text/plain\r\n"
        . "\r\n"
        . "file bytes\r\n"
        . "--{$boundary}--\r\n",
    );
});

it('reports the exact size of the body', function (): void {
    $factory = Fixtures::factory();

    $multipart = MultipartFile::create($factory, $factory->createStream(str_repeat('x', 5000)), 'a.txt', 'text/plain');

    expect($multipart->body()->getSize())->toBe(strlen((string) $multipart->body()));
});

it('uses a new boundary for every body', function (): void {
    $factory = Fixtures::factory();

    $first = MultipartFile::create($factory, $factory->createStream('a'), 'a.txt', 'text/plain');
    $second = MultipartFile::create($factory, $factory->createStream('a'), 'a.txt', 'text/plain');

    expect($first->contentType())->not->toBe($second->contentType());
});

it('escapes quotes and line breaks in the filename', function (): void {
    $factory = Fixtures::factory();

    $multipart = MultipartFile::create($factory, $factory->createStream('x'), "a\"b\r\nContent-Type: evil.txt", 'text/plain');

    $body = (string) $multipart->body();

    expect($body)->toContain('filename="a%22b%0D%0AContent-Type: evil.txt"')
        ->and(str_contains($body, "\r\nContent-Type: evil.txt"))->toBeFalse();
});

it('cannot be broken out of through the part content type', function (): void {
    $factory = Fixtures::factory();

    $multipart = MultipartFile::create($factory, $factory->createStream('x'), 'a.txt', "text/plain\r\nX-Injected: 1");

    $body = (string) $multipart->body();

    expect($body)->toContain("Content-Type: text/plainX-Injected: 1\r\n")
        ->and(str_contains($body, "\r\nX-Injected"))->toBeFalse();
});

it('keeps non-ASCII filenames as UTF-8', function (): void {
    $factory = Fixtures::factory();

    $multipart = MultipartFile::create($factory, $factory->createStream('x'), 'Übersicht.md', 'text/markdown');

    expect((string) $multipart->body())->toContain('filename="Übersicht.md"');
});
