<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * A single collection field descriptor — used both as an element of {@see CreateCollectionData::$fields}
 * and as the body of "add a field" requests. Semantic validation (supported type, settings) stays in
 * the CollectionManager.
 */
final class FieldData implements RequestData
{
    /**
     * @param array<string, mixed> $settings field options (e.g. nullable, unique, index, length, values)
     */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('required|string')]
        public readonly string $type,
        #[Rule('array')]
        public readonly array $settings = [],
    ) {
    }

    /** @return array{name: string, type: string, settings: array<string, mixed>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'settings' => $this->settings];
    }
}
