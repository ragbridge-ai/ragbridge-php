<?php

declare(strict_types=1);

use Ragbridge\Sync\ExternalId;

it('joins the table and the key with a colon', function (): void {
    expect(ExternalId::of('posts', 42))->toBe('posts:42')
        ->and(ExternalId::of('posts', 'abc'))->toBe('posts:abc');
});
