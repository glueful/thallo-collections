<?php

declare(strict_types=1);

namespace Thallo\Collections\Exceptions;

/**
 * Thrown by RelationResolver::expand() when a caller explicitly requests `?expand=<field>`
 * whose relation target is a collection the caller is NOT authorized to read.
 *
 * The URL collection's own scope is checked by CollectionScopeMiddleware, but the expand
 * target is a different collection with its own access policy — expanding it without a
 * per-target read check would let `A.read` leak a scoped collection B's rows. The HTTP layer
 * maps this to 403 (the caller asked for it explicitly, so a clear denial beats a silent skip).
 */
final class CollectionExpandForbiddenException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forField(string $field, string $targetCollection): self
    {
        return new self(sprintf(
            "Expanding field '%s' requires read access to the '%s' collection.",
            $field,
            $targetCollection,
        ));
    }
}
