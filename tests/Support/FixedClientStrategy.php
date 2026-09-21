<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Support;

use Closure;
use Http\Discovery\ClassDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

/**
 * Discovery strategy that makes php-http/discovery return a given PSR-18 client, so tests
 * can observe what a client created through discovery sends.
 *
 * Discovery keeps global state, so every test that installs the strategy must call reset().
 */
final class FixedClientStrategy implements DiscoveryStrategy
{
    private static ?ClientInterface $client = null;

    /** @var list<string>|null */
    private static ?array $original = null;

    public static function install(ClientInterface $client): void
    {
        self::$original ??= array_values([...ClassDiscovery::getStrategies()]);
        self::$client = $client;

        ClassDiscovery::prependStrategy(self::class);
        ClassDiscovery::clearCache();
    }

    /**
     * Removes every strategy so that nothing can be discovered.
     */
    public static function installNothing(): void
    {
        self::$original ??= array_values([...ClassDiscovery::getStrategies()]);

        ClassDiscovery::setStrategies([]);
        ClassDiscovery::clearCache();
    }

    public static function reset(): void
    {
        if (self::$original !== null) {
            ClassDiscovery::setStrategies(self::$original);
        }

        self::$client = null;
        self::$original = null;
        ClassDiscovery::clearCache();
    }

    /**
     * @param string $type
     *
     * @return list<array{class: Closure(): ClientInterface}>
     */
    public static function getCandidates($type)
    {
        if ($type !== ClientInterface::class || self::$client === null) {
            return [];
        }

        $client = self::$client;

        return [['class' => static fn(): ClientInterface => $client]];
    }
}
