<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client that answers from a queue and reads each request body while it is sent.
 *
 * A streamed upload closes its file once the request is sent, so the body has to be
 * consumed at send time to be inspected afterwards.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

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
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();

        return array_shift($this->responses) ?? throw new RuntimeException('No response queued.');
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
