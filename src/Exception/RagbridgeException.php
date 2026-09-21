<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws deliberately.
 *
 * Catch it to handle any failure of a call to the service in one place.
 */
interface RagbridgeException extends Throwable {}
