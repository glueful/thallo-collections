<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Physical column directive produced by ColumnMapper and consumed by the DDL planner.
 *
 * - type   : schema-builder method name (e.g. 'string', 'bigInteger', 'decimal', 'text')
 * - params : positional arguments to the builder call (e.g. [255], [12, 2])
 * - nullable / unique / index : column modifiers (index = plain index; created only
 *   when the column is not already unique — a unique constraint serves lookups)
 */
final class ColumnSpec
{
    /**
     * @param list<mixed> $params
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $params = [],
        public readonly bool $nullable = true,
        public readonly bool $unique = false,
        public readonly bool $index = false,
    ) {
    }
}
