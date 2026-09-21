<?php

declare(strict_types=1);

namespace Ragbridge\Symfony;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony bundle that registers the ragbridge client as an autowirable service.
 *
 * Configuration lives under the `ragbridge` key, see DependencyInjection\Configuration.
 */
final class RagbridgeBundle extends Bundle {}
