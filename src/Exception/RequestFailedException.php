<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service answered with a status that has no dedicated exception, for example 400,
 * 413 or 429, or with an unexpected redirect. A 409 is reported as the subclass
 * {@see ConflictException}.
 */
class RequestFailedException extends ApiException {}
