<?php

declare(strict_types=1);

namespace Thallo\Collections\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched after a collection (its physical table + definition) has been dropped.
 *
 * Pure domain event — the pack depends only on framework + contracts. App-side listeners
 * (audit, webhooks) bridge it without coupling to CollectionManager.
 */
final class CollectionDropped extends BaseEvent
{
    /**
     * @param string      $collectionName The logical name of the dropped collection.
     * @param string      $actorType      The actor type that dropped it (e.g. 'admin', 'system').
     * @param string|null $actorId        The actor's uuid, when known (for audit attribution).
     */
    public function __construct(
        public readonly string $collectionName,
        public readonly string $actorType,
        public readonly ?string $actorId,
    ) {
        parent::__construct();
    }
}
