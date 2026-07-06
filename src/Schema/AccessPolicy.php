<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Per-operation access policy for a collection's public data API.
 *
 * Each operation (read / write / delete) carries one access level:
 *   - public  — no authentication required.
 *   - scoped  — the `collections.{name}.{op}` capability, satisfied by an api-key scope or a
 *              session user's Aegis permission. `scoped` already implies authentication, so there
 *              is no separate "authenticated" level. This is the default.
 *
 * `scoped` is the default for every operation so a collection is never world-readable or
 * world-writable by accident — it must be explicitly opened up.
 */
final class AccessPolicy
{
    public const PUBLIC = 'public';
    public const SCOPED = 'scoped';

    public const LEVELS = [self::PUBLIC, self::SCOPED];

    public function __construct(
        public readonly string $read,
        public readonly string $write,
        public readonly string $delete,
    ) {
        foreach (['read' => $read, 'write' => $write, 'delete' => $delete] as $op => $level) {
            if (!in_array($level, self::LEVELS, true)) {
                throw new \InvalidArgumentException("Invalid access level '{$level}' for '{$op}'.");
            }
        }
    }

    /** The safe default: every operation requires the scoped capability. */
    public static function default(): self
    {
        return new self(self::SCOPED, self::SCOPED, self::SCOPED);
    }

    /**
     * Hydrate from stored/request data, normalizing unknown or missing levels to `scoped`
     * (fail-safe to the most restrictive level).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            read: self::normalize($data['read'] ?? null),
            write: self::normalize($data['write'] ?? null),
            delete: self::normalize($data['delete'] ?? null),
        );
    }

    private static function normalize(mixed $level): string
    {
        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : self::SCOPED;
    }

    /** @return array{read: string, write: string, delete: string} */
    public function toArray(): array
    {
        return ['read' => $this->read, 'write' => $this->write, 'delete' => $this->delete];
    }

    /**
     * The access level governing an operation. $operation is the normalized verb
     * (read | write | delete); anything else is treated as read.
     */
    public function forOperation(string $operation): string
    {
        return match ($operation) {
            'write' => $this->write,
            'delete' => $this->delete,
            default => $this->read,
        };
    }
}
