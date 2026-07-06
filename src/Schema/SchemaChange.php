<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Immutable value object representing a single DDL operation to apply to a collection's table.
 *
 * Allowed ops: create_table | add_field | drop_field | add_index | drop_index | drop_table
 *
 * For add_index / drop_index, `indexKind` says WHICH physical index the op targets:
 * 'unique' or 'plain'. The kind is fixed at plan time from the transition being made —
 * the materializer must never re-derive it from the field's (post-change) settings,
 * which is how "drop the plain index" once silently dropped a unique constraint.
 *
 * The `destructive` flag is true when the operation may cause data loss (drop_field,
 * drop_table) or requires a data pre-flight before it can be safely applied (add_index
 * for a unique constraint). Confirmation gating lives in CollectionManager's confirm-token
 * flow; the materializer uses the flag for the audit trail and the unique pre-flight only.
 */
final class SchemaChange
{
    public function __construct(
        public readonly string $op,
        public readonly ?CollectionField $field,
        public readonly bool $destructive,
        public readonly ?string $indexKind = null,
    ) {
    }
}
