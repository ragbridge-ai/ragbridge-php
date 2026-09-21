<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service failed to handle the request (HTTP 5xx). A 503 is reported as the subclass
 * {@see ServiceUnavailableException}.
 */
class ServerException extends ApiException {}
