<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The API key is missing, invalid or not allowed to perform the request (HTTP 401 or 403).
 */
final class AuthenticationException extends ApiException {}
