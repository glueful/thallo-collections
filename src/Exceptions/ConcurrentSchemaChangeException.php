<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown when a schema mutation loses the optimistic-concurrency check on the
 * collection definition (another change bumped schema_version after this one
 * loaded it). The surrounding transaction rolls back — including any DDL — so
 * the caller can safely reload and retry. Maps to HTTP 409.
 */
final class ConcurrentSchemaChangeException extends \RuntimeException
{
    public static function forCollection(string $name): self
    {
        return new self(sprintf(
            "Collection '%s' was modified concurrently. Reload the collection and retry the change.",
            $name,
        ));
    }
}
