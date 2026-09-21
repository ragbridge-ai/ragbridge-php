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
use Ragbridge\Dto\Document;
use Ragbridge\Dto\QueryResult;
use Ragbridge\Exception\ApiException;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\Internal\MultipartFile;

/**
 * Client for the ragbridge HTTP API.
 *
 * The client only handles transport: it builds requests, sends them through the injected
 * PSR-18 client and turns responses into typed objects or exceptions. Retrieval and
 * generation happen in the service.
 *
 * A failed call is reported as an exception implementing {@see Exception\RagbridgeException}.
 * Invalid arguments, such as a malformed base URL or a missing file, raise an
 * InvalidArgumentException instead.
 */
final readonly class RagbridgeClient
{
    private string $baseUrl;

    /**
     * @param string $baseUrl root URL of the service, for example http://localhost:8000
     * @param string|null $apiKey sent as a bearer token when set
     *
     * @throws InvalidArgumentException when the base URL is not an absolute http(s) URL
     */
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        string $baseUrl,
        private ?string $apiKey = null,
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
    }

    /**
     * Creates a client that uses whatever PSR-18 client and PSR-17 factories are installed.
     *
     * The implementations are located with php-http/discovery. Use the constructor to
     * choose them explicitly, for example to share the HTTP client of your application.
     *
     * @param string $baseUrl root URL of the service, for example http://localhost:8000
     * @param string|null $apiKey sent as a bearer token when set
     *
     * @throws InvalidArgumentException when the base URL is not an absolute http(s) URL
     * @throws \Http\Discovery\Exception\NotFoundException when no PSR-18 client or PSR-17 factory is installed
     */
    public static function create(string $baseUrl, ?string $apiKey = null): self
    {
        return new self(
            Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            $baseUrl,
            $apiKey,
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
        $body = ['question' => $question, 'top_k' => $topK];

        if ($mode !== null) {
            $body['mode'] = $mode->value;
        }

        if ($explain) {
            $body['explain'] = true;
        }

        return $this->hydrate(QueryResult::fromArray(...), $this->object($this->send('POST', '/query', $body)));
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

        $data = $this->send('POST', '/documents', stream: $multipart->body(), contentType: $multipart->contentType());

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
     * Sends a request and returns the decoded JSON body, or null when the response has none.
     *
     * @param array<string, mixed>|null $json request body, encoded as JSON
     * @param StreamInterface|null $stream raw request body, used instead of $json
     *
     * @throws Exception\RagbridgeException
     */
    private function send(
        string $method,
        string $path,
        ?array $json = null,
        ?StreamInterface $stream = null,
        ?string $contentType = null,
    ): mixed {
        $request = $this->requestFactory
            ->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Accept', 'application/json');

        if ($this->apiKey !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->apiKey);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->encode($json)));
        } elseif ($stream !== null) {
            $request = $request
                ->withHeader('Content-Type', $contentType ?? 'application/octet-stream')
                ->withBody($stream);
        }

        $response = $this->dispatch($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw $this->errorFor($status, $this->decodeLeniently($body));
        }

        return $body === '' ? null : $this->decode($body);
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
