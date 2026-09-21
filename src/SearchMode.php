<?php

declare(strict_types=1);

namespace Ragbridge;

/**
 * Retrieval strategy the service uses to find relevant chunks.
 */
enum SearchMode: string
{
    case Hybrid = 'hybrid';
    case Vector = 'vector';
    case Keyword = 'keyword';
}
