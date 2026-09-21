<?php

declare(strict_types=1);

namespace Ragbridge\Internal;

use Exception;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A multipart/form-data request body holding one file.
 *
 * The file is streamed: the body is the multipart header, the file stream and the closing
 * boundary chained together, so large files are never loaded into memory.
 *
 * @internal
 */
final readonly class MultipartFile
{
    private function __construct(
        private StreamInterface $body,
        private string $contentType,
    ) {}

    /**
     * @param string $field name of the form field
     * @param string $partContentType media type of the file, sent as the part's Content-Type
     *
     * @throws Exception when no secure random source is available for the boundary
     */
    public static function create(
        StreamFactoryInterface $streamFactory,
        StreamInterface $file,
        string $filename,
        string $partContentType,
        string $field = 'file',
    ): self {
        $boundary = 'ragbridge-' . bin2hex(random_bytes(16));

        $header = sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n",
            $boundary,
            self::quote($field),
            self::quote($filename),
            self::stripLineBreaks($partContentType),
        );

        $body = new ConcatStream(
            $streamFactory->createStream($header),
            $file,
            $streamFactory->createStream(sprintf("\r\n--%s--\r\n", $boundary)),
        );

        return new self($body, 'multipart/form-data; boundary=' . $boundary);
    }

    public function body(): StreamInterface
    {
        return $this->body;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * Escapes a value used inside a quoted header parameter (RFC 7578, section 4.2).
     */
    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }

    private static function stripLineBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
