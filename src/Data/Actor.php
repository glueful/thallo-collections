<?php

declare(strict_types=1);

namespace Thallo\Collections\Data;

/**
 * Readonly value object representing the actor that performed a data mutation.
 *
 * type: one of 'api_key', 'user', or 'admin'
 * id:   the actor's public identifier (UUID or key ID); null for anonymous/system actors
 */
final readonly class Actor
{
    public const TYPES = ['api_key', 'user', 'admin'];

    public function __construct(
        public string $type,
        public ?string $id,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Actor type must be one of api_key, user, admin; got '{$type}'.",
            );
        }
    }
}
