<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Http\Mock\Client as MockClient;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ragbridge\RagbridgeClient;

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

    public static function lastRequest(MockClient $http): RequestInterface
    {
        $request = $http->getLastRequest();
        Assert::assertInstanceOf(RequestInterface::class, $request, 'No request was sent.');

        return $request;
    }
}
