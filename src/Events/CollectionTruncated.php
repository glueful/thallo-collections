<?php

declare(strict_types=1);

namespace Thallo\Collections\Events;

use Glueful\Events\Contracts\BaseEvent;
use Thallo\Collections\Data\Actor;

/**
 * Dispatched after all rows in a collection's table have been deleted (admin truncate).
 *
 * No row payloads are carried — the rows are gone by the time this fires. Subscribers
 * (realtime, search indexers, cache invalidators, audit sinks) use collectionName to
 * invalidate everything they hold for the collection; deletedCount and the actor give
 * the audit trail the who/how-much that per-row events would otherwise have recorded.
 */
final class CollectionTruncated extends BaseEvent
{
    public function __construct(
        public readonly string $collectionName,
        public readonly int $deletedCount,
        public readonly Actor $actor,
    ) {
        parent::__construct();
    }
}
