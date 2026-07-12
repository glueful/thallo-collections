<?php

declare(strict_types=1);

namespace Thallo\Collections\Events;

use Glueful\Events\Contracts\BaseEvent;
use Thallo\Collections\Data\Actor;

/**
 * Dispatched after an existing row has been successfully updated in a collection's table.
 *
 * Subscribers (realtime, webhooks, search indexers, audit logs) listen to this
 * event to react to data mutations without coupling to RowRepository directly.
 */
final class CollectionRowUpdated extends BaseEvent
{
    /**
     * @param string               $collectionName  The logical name of the collection (not table name).
     * @param string               $rowUuid         The UUID of the updated row.
     * @param array<string, mixed> $row             The full stored row after the update.
     * @param Actor                $actor           The actor that updated the row (for audit attribution).
     */
    public function __construct(
        public readonly string $collectionName,
        public readonly string $rowUuid,
        public readonly array $row,
        public readonly Actor $actor,
        public readonly string $tenantUuid = '',
    ) {
        parent::__construct();
    }
}
