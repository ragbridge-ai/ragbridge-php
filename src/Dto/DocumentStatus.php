<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

/**
 * Processing state of an uploaded document.
 */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
