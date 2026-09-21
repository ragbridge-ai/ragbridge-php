<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Support;

use Ragbridge\RagbridgeClient;

/**
 * A service that asks for the client by type, to prove that it can be autowired.
 */
final readonly class ClientConsumer
{
    public function __construct(public RagbridgeClient $client) {}
}
