<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Sync\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * An entity manager on a fresh in-memory SQLite database, with the fixture entities' tables
 * created.
 */
final class EntityManagerFactory
{
    private function __construct() {}

    public static function create(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__], isDevMode: true);

        // Native lazy objects, on PHP 8.4+, replace generated proxy classes; configuring a
        // proxy directory is deprecated once they are available.
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/ragbridge-doctrine-proxies');
            $config->setProxyNamespace('Ragbridge\\Tests\\Symfony\\Sync\\Proxies');
            $config->setAutoGenerateProxyClasses(true);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $classes = array_map(
            $entityManager->getClassMetadata(...),
            [Post::class, TaggedPost::class, RestrictedPost::class],
        );

        (new SchemaTool($entityManager))->createSchema($classes);

        return $entityManager;
    }
}
