<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client that reads each request body from where the stream currently is, without
 * rewinding it first, and answers from a queue.
 *
 * Reading from the current position is what makes it possible to see whether a body that is
 * sent a second time was put back at its start.
 */
final class StreamReadingClient implements ClientInterface
{
    /** @var list<string> */
    public array $bodies = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->bodies[] = $request->getBody()->getContents();

        return array_shift($this->responses) ?? throw new RuntimeException('No response queued.');
    }
}
