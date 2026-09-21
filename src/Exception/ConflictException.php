<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

/**
 * The request conflicted with another change to the same document (HTTP 409).
 *
 * For a document identified by an external id this means that the request lost a race
 * with a delete. The state the service holds is consistent, so sending the request again
 * is the right response.
 *
 * It extends RequestFailedException, so code that catches that class still catches it.
 */
final class ConflictException extends RequestFailedException {}
