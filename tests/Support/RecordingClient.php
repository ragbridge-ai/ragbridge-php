<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-18 client that answers from a queue and reads each request body while it is sent.
 *
 * A streamed upload closes its file once the request is sent, so the body has to be
 * consumed at send time to be inspected afterwards.
 *
 * An entry of the queue that is a Throwable is thrown instead of returned, which simulates
 * a request that failed before a response arrived.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> */
    public array $bodies = [];

    /** @var list<ResponseInterface|Throwable> */
    private array $outcomes;

    public function __construct(ResponseInterface|Throwable ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        $outcome = array_shift($this->outcomes) ?? throw new RuntimeException('No response queued.');

        return $outcome instanceof Throwable ? throw $outcome : $outcome;
    }

    public function lastBody(): string
    {
        return $this->bodies[array_key_last($this->bodies)] ?? throw new RuntimeException('No request was sent.');
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new RuntimeException('No request was sent.');
    }
}
