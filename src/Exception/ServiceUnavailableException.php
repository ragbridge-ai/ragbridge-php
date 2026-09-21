<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The service cannot handle the request at the moment (HTTP 503).
 *
 * It answers this when a dependency, such as its database or its job queue, is down. The
 * condition is temporary, so the request can be sent again later. For a document that is
 * saved with an external id, the service has marked the document as failed in that case;
 * sending the same record again processes it.
 *
 * It extends ServerException, so code that catches that class still catches it.
 */
final class ServiceUnavailableException extends ServerException {}
