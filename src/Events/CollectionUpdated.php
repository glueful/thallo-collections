<?php

declare(strict_types=1);

namespace Thallo\Collections\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched after a change to an existing collection's schema or access policy (a field or
 * index added/removed, or the access policy replaced). Field-order changes are cosmetic
 * metadata and do not emit this.
 *
 * Pure domain event — the pack depends only on framework + contracts. App-side listeners
 * (audit, webhooks) bridge it without coupling to CollectionManager.
 */
final class CollectionUpdated extends BaseEvent
{
    /**
     * @param string      $collectionName The logical name of the collection.
     * @param string      $change         What changed: 'field_added' | 'field_dropped'
     *                                     | 'index_added' | 'index_removed' | 'access_updated'.
     * @param string|null $detail         The affected field/index name, when applicable.
     * @param string      $actorType      The actor type that made the change.
     * @param string|null $actorId        The actor's uuid, when known (for audit attribution).
     */
    public function __construct(
        public readonly string $collectionName,
        public readonly string $change,
        public readonly ?string $detail,
        public readonly string $actorType,
        public readonly ?string $actorId,
        public readonly string $tenantUuid = '',
    ) {
        parent::__construct();
    }
}
