<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by CollectionManager when a destructive operation (dropField / dropCollection)
 * is attempted on a non-empty data table without the required confirmation token.
 *
 * The caller must re-invoke the operation with $opts['confirm'] set to the exact field
 * name (for dropField) or the exact collection name (for dropCollection).
 *
 * Empty-table light path: when the data table has zero rows, confirmation is NOT required
 * and this exception is never thrown — the operation proceeds immediately.
 */
final class DestructiveConfirmationRequiredException extends \RuntimeException
{
    public static function forField(string $fieldName, string $collectionName): self
    {
        return new self(sprintf(
            "Dropping field '%s' from '%s' requires confirmation: pass opts['confirm'] = '%s'."
            . " This collection has existing data rows.",
            $fieldName,
            $collectionName,
            $fieldName,
        ));
    }

    public static function forCollection(string $collectionName): self
    {
        return new self(sprintf(
            "Dropping collection '%s' requires confirmation: pass opts['confirm'] = '%s'."
            . " This collection has existing data rows.",
            $collectionName,
            $collectionName,
        ));
    }
}
