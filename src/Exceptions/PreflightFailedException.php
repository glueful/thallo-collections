<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by SchemaMaterializer when a unique-index pre-flight detects duplicate values
 * in the target column, preventing a unique constraint from being safely applied.
 *
 * This is raised BEFORE any audit row is written — callers see it as a validation error,
 * not a partially-applied change.
 */
final class PreflightFailedException extends \RuntimeException
{
    public static function duplicateValues(string $column, string $table): self
    {
        return new self(sprintf(
            "Cannot add unique index on '%s'.'%s': duplicate values exist — deduplicate rows first.",
            $table,
            $column,
        ));
    }
}
