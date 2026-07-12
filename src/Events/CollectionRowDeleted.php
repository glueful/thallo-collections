<?php

declare(strict_types=1);

namespace Thallo\Collections\Events;

use Glueful\Events\Contracts\BaseEvent;
use Thallo\Collections\Data\Actor;

/**
 * Dispatched after a row has been successfully deleted from a collection's table.
 *
 * No row payload is carried: the row is gone by the time this event fires.
 * Subscribers (realtime, search indexers, cache invalidators) use collectionName
 * and rowUuid to identify what was removed.
 */
final class CollectionRowDeleted extends BaseEvent
{
    /**
     * @param string $collectionName  The logical name of the collection (not table name).
     * @param string $rowUuid         The UUID of the deleted row.
     * @param Actor  $actor           The actor that performed the delete (for audit attribution).
     */
    public function __construct(
        public readonly string $collectionName,
        public readonly string $rowUuid,
        public readonly Actor $actor,
        public readonly string $tenantUuid = '',
    ) {
        parent::__construct();
    }
}
