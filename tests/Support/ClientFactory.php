<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use ArrayObject;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Assert;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ragbridge\RagbridgeClient;
use Ragbridge\RetryPolicy;
use Throwable;

final class ClientFactory
{
    public static function make(
        MockClient $http,
        ?string $apiKey = 'secret-key',
        string $baseUrl = 'http://localhost:8000',
    ): RagbridgeClient {
        $factory = Fixtures::factory();

        return new RagbridgeClient($http, $factory, $factory, $baseUrl, $apiKey);
    }

    /**
     * A client whose HTTP client answers with the given responses, and that HTTP client.
     *
     * @return array{RagbridgeClient, MockClient}
     */
    public static function answering(ResponseInterface ...$responses): array
    {
        $http = new MockClient();

        foreach ($responses as $response) {
            $http->addResponse($response);
        }

        return [self::make($http), $http];
    }

    /**
     * A client with a retry policy, whose HTTP client answers with the given outcomes in order.
     * A Throwable is thrown by the HTTP client instead of being returned. The pauses between
     * tries are recorded in milliseconds instead of being waited for.
     *
     * @param RetryPolicy|null $policy null builds a client without retries
     *
     * @return array{RagbridgeClient, RecordingClient, ArrayObject<int, int>}
     */
    public static function retrying(?RetryPolicy $policy, ResponseInterface|Throwable ...$outcomes): array
    {
        $http = new RecordingClient(...$outcomes);
        $sleeps = self::sleeps();

        return [self::withSleeps($http, $policy, $sleeps), $http, $sleeps];
    }

    /**
     * @return ArrayObject<int, int>
     */
    public static function sleeps(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @param ArrayObject<int, int> $sleeps receives the pauses, in milliseconds
     */
    public static function withSleeps(ClientInterface $http, ?RetryPolicy $policy, ArrayObject $sleeps): RagbridgeClient
    {
        $factory = Fixtures::factory();

        return new RagbridgeClient(
            $http,
            $factory,
            $factory,
            'http://localhost:8000',
            'secret-key',
            $policy,
            static function (int $milliseconds) use ($sleeps): void {
                $sleeps[] = $milliseconds;
            },
        );
    }

    public static function lastRequest(MockClient $http): RequestInterface
    {
        $request = $http->getLastRequest();
        Assert::assertInstanceOf(RequestInterface::class, $request, 'No request was sent.');

        return $request;
    }
}
