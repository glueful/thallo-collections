<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by DdlPlanner when a schema change cannot be expressed as a safe operation.
 *
 * Blocked operations in v1:
 *  - retype: a field whose `type` key changed
 *  - any other storage-signature change (nullable, length, precision, scale,
 *    bigint, target, multi) on an existing same-name field
 *
 * Callers must drop + re-add the field to remodel its column.
 */
final class BlockedSchemaChangeException extends \RuntimeException
{
    public static function retype(string $fieldName, string $fromType, string $toType): self
    {
        return new self(sprintf(
            "Cannot retype field '%s' from '%s' to '%s' in v1."
            . " Drop and re-add the field to change its type.",
            $fieldName,
            $fromType,
            $toType,
        ));
    }

    /**
     * @param array<string, mixed> $from  Normalized storage signature before the change.
     * @param array<string, mixed> $to    Normalized storage signature after the change.
     */
    public static function storageSignatureChanged(
        string $fieldName,
        array $from,
        array $to,
    ): self {
        $changed = [];
        foreach ($to as $key => $value) {
            if (($from[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        return new self(sprintf(
            "Cannot change the storage definition of field '%s' in-place in v1 (changed: %s)."
            . " Drop and re-add the field to remodel its column.",
            $fieldName,
            implode(', ', $changed),
        ));
    }
}
