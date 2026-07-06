<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by QueryCompiler when a list query contains an invalid parameter.
 *
 * Covers:
 *   - unknown field name in filter, sort, or fields projection
 *   - filter on a field whose registry capability 'filterable' = false
 *   - sort on a field whose registry capability 'sortable' = false
 *   - unknown filter operator
 *   - malformed parameter shapes (array where a scalar is expected, empty `in` list)
 */
final class InvalidQueryException extends \InvalidArgumentException
{
    public static function unknownField(string $field, string $context): self
    {
        return new self(sprintf(
            "Unknown field '%s' in %s. Only fields defined in the collection and system columns are allowed.",
            $field,
            $context,
        ));
    }

    public static function nonFilterableField(string $field, string $type): self
    {
        return new self(sprintf(
            "Field '%s' (type '%s') is not filterable.",
            $field,
            $type,
        ));
    }

    public static function nonSortableField(string $field, string $type): self
    {
        return new self(sprintf(
            "Field '%s' (type '%s') is not sortable.",
            $field,
            $type,
        ));
    }

    public static function unknownOperator(string $op, string $field): self
    {
        return new self(sprintf(
            "Unknown filter operator '%s' on field '%s'. Allowed: eq, ne, lt, lte, gt, gte, like, in, null.",
            $op,
            $field,
        ));
    }

    public static function malformedParam(string $param, string $expected): self
    {
        return new self(sprintf('Malformed %s parameter: expected %s.', $param, $expected));
    }
}
