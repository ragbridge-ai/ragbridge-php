<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Sync\Support;

use Ragbridge\Symfony\Sync\SyncMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records every message dispatched to it, instead of sending it anywhere, so a test can
 * inspect what {@see \Ragbridge\Symfony\Sync\EntityChangeListener} or
 * {@see \Ragbridge\Symfony\Sync\SyncCommand} dispatched.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return Envelope::wrap($message, $stamps);
    }

    /**
     * The dispatched messages that are {@see SyncMessage}, in order; everything else that
     * was dispatched is left out.
     *
     * @return list<SyncMessage>
     */
    public function syncMessages(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn(object $message): bool => $message instanceof SyncMessage,
        ));
    }
}
