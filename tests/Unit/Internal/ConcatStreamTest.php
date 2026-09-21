<?php

declare(strict_types=1);

use Ragbridge\Internal\ConcatStream;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\UnsizedStream;

function concat(string ...$parts): ConcatStream
{
    $factory = Fixtures::factory();

    return new ConcatStream(...array_map($factory->createStream(...), $parts));
}

it('presents its parts as one stream', function (): void {
    $stream = concat('Hello, ', 'streamed ', 'world');

    expect($stream->getContents())->toBe('Hello, streamed world')
        ->and($stream->eof())->toBeTrue()
        ->and($stream->tell())->toBe(21);
});

it('reports the total size', function (): void {
    expect(concat('abc', '', 'defg')->getSize())->toBe(7);
});

it('reads across part boundaries in chunks', function (): void {
    $stream = concat('abc', 'defg', 'h');

    expect($stream->read(2))->toBe('ab')
        ->and($stream->read(3))->toBe('cde')
        ->and($stream->read(100))->toBe('fgh')
        ->and($stream->read(10))->toBe('')
        ->and($stream->eof())->toBeTrue();
});

it('converts to a string from the start regardless of the position', function (): void {
    $stream = concat('abc', 'def');
    $stream->read(4);

    expect((string) $stream)->toBe('abcdef');
});

it('seeks to absolute and relative positions', function (): void {
    $stream = concat('0123', '4567', '89');

    $stream->seek(5);
    expect($stream->read(3))->toBe('567');

    $stream->seek(-2, SEEK_CUR);
    expect($stream->read(2))->toBe('67');

    $stream->seek(-1, SEEK_END);
    expect($stream->getContents())->toBe('9')
        ->and($stream->tell())->toBe(10);

    $stream->rewind();
    expect($stream->getContents())->toBe('0123456789');
});

it('can be read again after reaching the end and seeking back', function (): void {
    $stream = concat('ab', 'cd');
    $stream->getContents();

    $stream->seek(2);

    expect($stream->eof())->toBeFalse()
        ->and($stream->getContents())->toBe('cd');
});

it('rejects seeking outside the stream', function (int $offset): void {
    expect(fn() => concat('abc')->seek($offset))->toThrow(RuntimeException::class, 'Cannot seek');
})->with([
    'before the start' => [-1],
    'past the end' => [4],
]);

it('rejects an invalid whence', function (): void {
    expect(fn() => concat('abc')->seek(0, 99))->toThrow(InvalidArgumentException::class);
});

it('is seekable only when every part is sized and seekable', function (): void {
    $factory = Fixtures::factory();

    expect(concat('a', 'b')->isSeekable())->toBeTrue()
        ->and((new ConcatStream($factory->createStream('a'), new UnsizedStream($factory->createStream('b'))))->isSeekable())->toBeFalse()
        ->and((new ConcatStream($factory->createStream('a'), new UnsizedStream($factory->createStream('b'))))->getSize())->toBeNull();
});

it('still reads a stream that is not seekable', function (): void {
    $factory = Fixtures::factory();
    $stream = new ConcatStream($factory->createStream('head-'), new UnsizedStream($factory->createStream('body-')), $factory->createStream('tail'));

    expect((string) $stream)->toBe('head-body-tail');
});

it('is read only', function (): void {
    $stream = concat('abc');

    expect($stream->isReadable())->toBeTrue()
        ->and($stream->isWritable())->toBeFalse()
        ->and(fn() => $stream->write('x'))->toThrow(RuntimeException::class)
        ->and(fn() => $stream->read(-1))->toThrow(InvalidArgumentException::class);
});

it('handles no parts', function (): void {
    $stream = new ConcatStream();

    expect($stream->getContents())->toBe('')
        ->and($stream->getSize())->toBe(0)
        ->and($stream->eof())->toBeTrue();
});

it('closes every part', function (): void {
    $factory = Fixtures::factory();
    $part = $factory->createStream('abc');
    $stream = new ConcatStream($part);

    $stream->close();

    expect($part->getSize())->toBeNull()
        ->and($stream->eof())->toBeTrue()
        ->and($stream->detach())->toBeNull()
        ->and($stream->getMetadata())->toBe([])
        ->and($stream->getMetadata('uri'))->toBeNull();
});
