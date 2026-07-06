<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by RowRepository when a row cannot be found by UUID.
 */
final class RowNotFoundException extends \DomainException
{
    public static function forUuid(string $tableName, string $uuid): self
    {
        return new self(sprintf(
            "Row with UUID '%s' not found in table '%s'.",
            $uuid,
            $tableName,
        ));
    }
}
