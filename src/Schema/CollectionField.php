<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Readonly value object representing a single field in a collection schema.
 *
 * Settings keys (all optional):
 *   length, precision, scale, nullable, unique, index, bigint, values, target, multi
 */
final class CollectionField
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $settings = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            settings: (array) ($data['settings'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'type'     => $this->type,
            'settings' => $this->settings,
        ];
    }
}
