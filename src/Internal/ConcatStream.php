<?php

declare(strict_types=1);

namespace Ragbridge\Internal;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Read-only stream that presents several streams as one, in order.
 *
 * It lets a multipart body be assembled from a header, a file stream and a trailer
 * without copying the file into memory.
 *
 * @internal
 */
final class ConcatStream implements StreamInterface
{
    /** @var list<StreamInterface> */
    private array $parts;

    /** Index of the part being read; equal to the number of parts once everything is read. */
    private int $index = 0;

    private int $position = 0;

    public function __construct(StreamInterface ...$parts)
    {
        $this->parts = array_values($parts);
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        foreach ($this->parts as $part) {
            $part->close();
        }

        $this->parts = [];
        $this->index = 0;
        $this->position = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        $size = 0;

        foreach ($this->parts as $part) {
            $partSize = $part->getSize();

            if ($partSize === null) {
                return null;
            }

            $size += $partSize;
        }

        return $size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->index >= count($this->parts);
    }

    public function isSeekable(): bool
    {
        if ($this->getSize() === null) {
            return false;
        }

        foreach ($this->parts as $part) {
            if (! $part->isSeekable()) {
                return false;
            }
        }

        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $size = $this->getSize();

        if ($size === null || ! $this->isSeekable()) {
            throw new RuntimeException('The stream is not seekable.');
        }

        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $size + $offset,
            default => throw new InvalidArgumentException(sprintf('Invalid whence value %d.', $whence)),
        };

        if ($target < 0 || $target > $size) {
            throw new RuntimeException(sprintf('Cannot seek to position %d in a stream of %d bytes.', $target, $size));
        }

        $remaining = $target;
        $current = null;

        foreach ($this->parts as $i => $part) {
            $partSize = $part->getSize() ?? 0;

            if ($current !== null) {
                $part->rewind();
            } elseif ($remaining < $partSize) {
                $part->seek($remaining);
                $current = $i;
            } else {
                $part->seek($partSize);
                $remaining -= $partSize;
            }
        }

        $this->index = $current ?? count($this->parts);
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('The length to read cannot be negative.');
        }

        $buffer = '';

        while (strlen($buffer) < $length && $this->index < count($this->parts)) {
            $part = $this->parts[$this->index];
            $chunk = $part->read($length - strlen($buffer));
            $buffer .= $chunk;

            if ($part->eof()) {
                $this->index++;
            } elseif ($chunk === '') {
                break;
            }
        }

        $this->position += strlen($buffer);

        return $buffer;
    }

    public function getContents(): string
    {
        $contents = '';

        while (! $this->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
