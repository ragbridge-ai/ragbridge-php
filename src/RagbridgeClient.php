<?php

declare(strict_types=1);

namespace Ragbridge;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Ragbridge\Dto\AgentResult;
use Ragbridge\Dto\Document;
use Ragbridge\Dto\HealthStatus;
use Ragbridge\Dto\QueryResult;
use Ragbridge\Dto\SearchResult;
use Ragbridge\Exception\ApiException;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\Internal\MultipartFile;
use Ragbridge\Internal\RetryAfter;
use RuntimeException;
use Throwable;

/**
 * Client for the ragbridge HTTP API.
 *
 * The client only handles transport: it builds requests, sends them through the injected
 * PSR-18 client and turns responses into typed objects or exceptions. Retrieval and
 * generation happen in the service.
 *
 * The class is not final so that applications and framework facades can replace it with a
 * test double. Its state is immutable.
 *
 * Failed requests are not repeated unless a {@see RetryPolicy} is given.
 *
 * A failed call is reported as an exception implementing {@see Exception\RagbridgeException}.
 * Invalid arguments, such as a malformed base URL or a missing file, raise an
 * InvalidArgumentException instead.
 */
class RagbridgeClient
{
    private readonly string $baseUrl;

    private readonly ?string $apiKey;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /**
     * @param string $baseUrl root URL of the service, for example http://localhost:8000
     * @param string|null $apiKey sent as a bearer token; null or an empty string means no key
     * @param RetryPolicy|null $retryPolicy when set, failed requests are sent again as the policy says
     * @param Closure(int): void|null $sleep waits for the given number of milliseconds between two
     *                                       tries; usleep() when null. Replace it in tests to avoid
     *                                       waiting.
     *
     * @throws InvalidArgumentException when the base URL is not an absolute http(s) URL
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        string $baseUrl,
        ?string $apiKey = null,
        private readonly ?RetryPolicy $retryPolicy = null,
        ?Closure $sleep = null,
    ) {
        $parts = parse_url($baseUrl);

        if (
            $parts === false
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
        ) {
            throw new InvalidArgumentException(sprintf(
                'The base URL must be an absolute http or https URL, "%s" given.',
                $baseUrl,
            ));
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey === '' ? null : $apiKey;
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Creates a client that uses whatever PSR-18 client and PSR-17 factories are installed.
     *
     * The implementations are located with php-http/discovery. Use the constructor to
     * choose them explicitly, for example to share the HTTP client of your application.
     *
     * @param string $baseUrl root URL of the service, for example http://localhost:8000
     * @param string|null $apiKey sent as a bearer token when set
     * @param RetryPolicy|null $retryPolicy when set, failed requests are sent again as the policy says
     *
     * @throws InvalidArgumentException when the base URL is not an absolute http(s) URL
     * @throws \Http\Discovery\Exception\NotFoundException when no PSR-18 client or PSR-17 factory is installed
     */
    public static function create(string $baseUrl, ?string $apiKey = null, ?RetryPolicy $retryPolicy = null): self
    {
        return new self(
            Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            $baseUrl,
            $apiKey,
            $retryPolicy,
        );
    }

    /**
     * Asks a question and returns the answer with the sources it is based on.
     *
     * @param int $topK number of chunks to retrieve, the service accepts 1 to 20
     * @param SearchMode|null $mode retrieval strategy, the service default when null
     * @param bool $explain include retrieval details in each source
     *
     * @throws Exception\RagbridgeException
     */
    public function query(
        string $question,
        int $topK = 5,
        ?SearchMode $mode = null,
        bool $explain = false,
    ): QueryResult {
        $body = ['question' => $question, ...$this->retrievalOptions($topK, $mode, $explain)];

        return $this->hydrate(QueryResult::fromArray(...), $this->object($this->send('POST', '/query', $body)));
    }

    /**
     * Searches the documents and returns the matching chunks, without generating an answer.
     *
     * It runs the same retrieval as {@see query()} and stops before generation. Use it when
     * your application reasons over the chunks itself.
     *
     * @param int $topK number of chunks to retrieve, the service accepts 1 to 20
     * @param SearchMode|null $mode retrieval strategy, the service default when null
     * @param bool $explain include retrieval details in each hit
     *
     * @throws Exception\RagbridgeException
     */
    public function search(
        string $query,
        int $topK = 5,
        ?SearchMode $mode = null,
        bool $explain = false,
    ): SearchResult {
        $body = ['query' => $query, ...$this->retrievalOptions($topK, $mode, $explain)];

        return $this->hydrate(SearchResult::fromArray(...), $this->object($this->send('POST', '/search', $body)));
    }

    /**
     * Answers a question that may need several searches.
     *
     * The service searches up to $maxSteps times, then answers from everything it found.
     * The result lists the searches it ran, so a wrong answer can be traced back to what
     * was looked for. The call is synchronous and, with a local model, can take a while:
     * give your HTTP client a generous timeout.
     *
     * @param int|null $maxSteps most searches to run, the service default when null
     *
     * @throws Exception\RagbridgeException
     */
    public function agent(string $question, ?int $maxSteps = null): AgentResult
    {
        $body = ['question' => $question];

        if ($maxSteps !== null) {
            $body['max_steps'] = $maxSteps;
        }

        return $this->hydrate(AgentResult::fromArray(...), $this->object($this->send('POST', '/agent', $body)));
    }

    /**
     * Uploads a file from disk. The file is streamed, not read into memory.
     *
     * The service accepts plain text, Markdown and PDF. Uploading the same content again
     * returns the existing document. Large files are processed asynchronously, so the
     * returned document can still be pending: poll {@see document()} until it is ready.
     *
     * @param string|null $filename name stored in the service, the file's own name when null
     * @param string|null $contentType media type, guessed from the file extension when null
     *
     * @throws InvalidArgumentException when the file does not exist or is not readable
     * @throws Exception\RagbridgeException
     */
    public function upload(string $path, ?string $filename = null, ?string $contentType = null): Document
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(sprintf('The file "%s" does not exist or is not readable.', $path));
        }

        $stream = $this->streamFactory->createStreamFromFile($path, 'rb');

        try {
            return $this->uploadStream($stream, $filename ?? basename($path), $contentType);
        } finally {
            $stream->close();
        }
    }

    /**
     * Uploads the remaining content of a stream, which the caller keeps ownership of.
     *
     * @param string $filename name stored in the service
     * @param string|null $contentType media type, guessed from the filename extension when null
     *
     * @throws Exception\RagbridgeException
     */
    public function uploadStream(StreamInterface $stream, string $filename, ?string $contentType = null): Document
    {
        $multipart = MultipartFile::create(
            $this->streamFactory,
            $stream,
            $filename,
            $contentType ?? self::guessContentType($filename),
        );

        // A retry has to send the body again from where it started, which may not be the
        // start of the caller's stream. Without a way back the upload is not retried.
        $body = $multipart->body();
        $start = $stream->isSeekable() ? $stream->tell() : 0;
        $rewind = $body->isSeekable()
            ? static function () use ($body, $stream, $start): void {
                $body->rewind();
                $stream->seek($start);
            }
        : null;

        $data = $this->send('POST', '/documents', stream: $body, contentType: $multipart->contentType(), rewind: $rewind);

        return $this->hydrate(Document::fromArray(...), $this->object($data));
    }

    /**
     * Lists the documents stored in the service.
     *
     * @return list<Document>
     *
     * @throws Exception\RagbridgeException
     */
    public function documents(): array
    {
        $items = $this->objects($this->send('GET', '/documents'));

        return array_map(fn(array $item): Document => $this->hydrate(Document::fromArray(...), $item), $items);
    }

    /**
     * Fetches one document, for example to check whether an upload has finished processing.
     *
     * @throws NotFoundException when no document has this id
     * @throws Exception\RagbridgeException
     */
    public function document(string $id): Document
    {
        $data = $this->send('GET', '/documents/' . rawurlencode($id));

        return $this->hydrate(Document::fromArray(...), $this->object($data));
    }

    /**
     * Deletes a document together with everything derived from it.
     *
     * @throws NotFoundException when no document has this id
     * @throws Exception\RagbridgeException
     */
    public function deleteDocument(string $id): void
    {
        $this->send('DELETE', '/documents/' . rawurlencode($id));
    }

    /**
     * Checks that the service process is running (liveness). It does not check the database.
     *
     * @throws Exception\RagbridgeException
     */
    public function health(): HealthStatus
    {
        return $this->hydrate(HealthStatus::fromArray(...), $this->object($this->send('GET', '/health')));
    }

    /**
     * Checks that the service can serve requests (readiness), which includes its database.
     *
     * A service that is not ready answers with HTTP 503, which is reported as a
     * {@see ServerException}, like any other failed call.
     *
     * @throws ServerException when the service is running but not ready
     * @throws Exception\RagbridgeException
     */
    public function readiness(): HealthStatus
    {
        return $this->hydrate(HealthStatus::fromArray(...), $this->object($this->send('GET', '/health/ready')));
    }

    /**
     * The request fields that query and search have in common. Optional fields are left out
     * unless they are set, so the service applies its own defaults.
     *
     * @return array<string, mixed>
     */
    private function retrievalOptions(int $topK, ?SearchMode $mode, bool $explain): array
    {
        $options = ['top_k' => $topK];

        if ($mode !== null) {
            $options['mode'] = $mode->value;
        }

        if ($explain) {
            $options['explain'] = true;
        }

        return $options;
    }

    /**
     * Sends a request and returns the decoded JSON body, or null when the response has none.
     *
     * When a retry policy applies to the request, a failure that the policy considers
     * transient is followed by another try, after a pause.
     *
     * @param array<string, mixed>|null $json request body, encoded as JSON
     * @param StreamInterface|null $stream raw request body, used instead of $json
     * @param Closure(): void|null $rewind puts $stream back at its start; the request is retried
     *                                     only when this is given, as the body cannot be sent twice otherwise
     *
     * @throws Exception\RagbridgeException
     */
    private function send(
        string $method,
        string $path,
        ?array $json = null,
        ?StreamInterface $stream = null,
        ?string $contentType = null,
        ?Closure $rewind = null,
    ): mixed {
        $policy = $this->retryPolicy !== null
            && $this->retryPolicy->appliesTo($method)
            && ($stream === null || $rewind !== null)
                ? $this->retryPolicy
                : null;

        $attempt = 1;

        while (true) {
            $request = $this->buildRequest($method, $path, $json, $stream, $contentType);

            try {
                $response = $this->dispatch($request);
            } catch (TransportException $e) {
                $this->pauseBeforeRetry($policy, $attempt++, null, $e, $rewind);

                continue;
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                return $body === '' ? null : $this->decode($body);
            }

            $error = $this->errorFor($status, $this->decodeLeniently($body));

            if ($policy === null || ! $policy->isRetryableStatus($status)) {
                throw $error;
            }

            $retryAfter = RetryAfter::milliseconds($response->getHeaderLine('Retry-After'));
            $this->pauseBeforeRetry($policy, $attempt++, $retryAfter, $error, $rewind);
        }
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function buildRequest(
        string $method,
        string $path,
        ?array $json,
        ?StreamInterface $stream,
        ?string $contentType,
    ): RequestInterface {
        $request = $this->requestFactory
            ->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Accept', 'application/json');

        if ($this->apiKey !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->apiKey);
        }

        if ($json !== null) {
            // A new body for every try: a sent stream may have been read to its end.
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->encode($json)));
        } elseif ($stream !== null) {
            $request = $request
                ->withHeader('Content-Type', $contentType ?? 'application/octet-stream')
                ->withBody($stream);
        }

        return $request;
    }

    /**
     * Waits before the next try, or throws the failure when there is not going to be one.
     *
     * @param RetryPolicy|null $policy null when the request is not to be retried at all
     * @param int $attempt number of the try that failed, starting at 1
     * @param int|null $retryAfterMs wait requested by the service
     * @param Closure(): void|null $rewind puts a streamed body back at its start
     *
     * @throws Throwable the failure, when the request is not retried
     */
    private function pauseBeforeRetry(
        ?RetryPolicy $policy,
        int $attempt,
        ?int $retryAfterMs,
        Throwable $failure,
        ?Closure $rewind,
    ): void {
        $delay = $policy === null || $attempt >= $policy->maxAttempts
            ? null
            : $policy->delayMs($attempt, $retryAfterMs);

        if ($delay === null) {
            throw $failure;
        }

        if ($rewind !== null) {
            try {
                $rewind();
            } catch (RuntimeException) {
                throw $failure;
            }
        }

        ($this->sleep)($delay);
    }

    /**
     * @throws TransportException
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::fromThrowable($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                sprintf('The request body cannot be encoded as JSON: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @throws InvalidResponseException
     */
    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidResponseException::because('the body is not valid JSON', $e);
        }
    }

    /**
     * Error bodies are best effort: the exception is thrown whether or not they decode.
     */
    private function decodeLeniently(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function errorFor(int $status, mixed $body): ApiException
    {
        return match (true) {
            $status === 401, $status === 403 => AuthenticationException::fromResponse($status, $body),
            $status === 404 => NotFoundException::fromResponse($status, $body),
            $status === 422 => ValidationException::fromResponse($status, $body),
            $status >= 500 => ServerException::fromResponse($status, $body),
            default => RequestFailedException::fromResponse($status, $body),
        };
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidResponseException
     */
    private function object(mixed $decoded): array
    {
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw InvalidResponseException::because(sprintf('expected a JSON object, got %s', get_debug_type($decoded)));
        }

        return $decoded;
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws InvalidResponseException
     */
    private function objects(mixed $decoded): array
    {
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw InvalidResponseException::because(sprintf('expected a JSON list, got %s', get_debug_type($decoded)));
        }

        $objects = [];

        foreach ($decoded as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw InvalidResponseException::because(sprintf('expected item %d to be a JSON object, got %s', $index, get_debug_type($item)));
            }

            $objects[] = $item;
        }

        return $objects;
    }

    private static function guessContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'md', 'markdown' => 'text/markdown',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * @template T
     *
     * @param Closure(array<mixed>): T $factory
     * @param array<mixed> $data
     *
     * @return T
     *
     * @throws InvalidResponseException
     */
    private function hydrate(Closure $factory, array $data): mixed
    {
        try {
            return $factory($data);
        } catch (InvalidArgumentException $e) {
            throw InvalidResponseException::because($e->getMessage(), $e);
        }
    }
}
