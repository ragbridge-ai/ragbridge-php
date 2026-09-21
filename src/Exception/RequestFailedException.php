<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service answered with a status that has no dedicated exception, for example 400,
 * 409, 413 or 429, or with an unexpected redirect.
 */
final class RequestFailedException extends ApiException {}
