<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Wraps a stream and hides its size and seekability, like a socket or pipe.
 */
final readonly class UnsizedStream implements StreamInterface
{
    public function __construct(private StreamInterface $inner) {}

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
