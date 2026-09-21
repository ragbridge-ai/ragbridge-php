<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service failed to handle the request (HTTP 5xx).
 */
final class ServerException extends ApiException {}
