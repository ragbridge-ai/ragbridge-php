<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

it('registers the package namespaces with the autoloader', function (): void {
    $prefixes = [];

    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        $prefixes = [...$prefixes, ...array_keys($loader->getPrefixesPsr4())];
    }

    expect($prefixes)->toContain('Ragbridge\\', 'Ragbridge\\Tests\\');
});
