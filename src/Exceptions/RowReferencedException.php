<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by RelationResolver::assertNotReferenced when a row that would be deleted
 * is still referenced by a relation field in another collection.
 *
 * This enforces restrict-delete semantics for collection↔collection relations.
 */
final class RowReferencedException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Build an exception describing which collection + field holds the reference.
     */
    public static function forUuid(
        string $targetCollection,
        string $uuid,
        string $referencingCollection,
        string $referencingField,
    ): self {
        return new self(sprintf(
            "Row '%s' in collection '%s' is referenced by field '%s' in collection '%s' and cannot be deleted.",
            $uuid,
            $targetCollection,
            $referencingField,
            $referencingCollection,
        ));
    }
}
