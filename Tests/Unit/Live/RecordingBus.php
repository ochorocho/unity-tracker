<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Live;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collects dispatched envelopes instead of handling them.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->envelopes[] = $envelope;
        return $envelope;
    }

    /**
     * @return list<object>
     */
    public function messages(): array
    {
        return array_map(static fn(Envelope $envelope): object => $envelope->getMessage(), $this->envelopes);
    }
}
