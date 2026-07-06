<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for creating a collection (`POST /v1/admin/collections`).
 *
 * `access` is the per-operation policy `{read, write, delete}` (each `public` or `scoped`); it is
 * normalized by AccessPolicy in the manager, defaulting to all-scoped when absent.
 */
final class CreateCollectionData implements RequestData
{
    /**
     * @param list<FieldData>      $fields
     * @param array<string, mixed> $access {read?, write?, delete?} access levels
     * @param list<string>         $field_order display order of all column names (system + custom)
     */
    public function __construct(
        #[Rule('required|string|regex:/\A[a-z][a-z0-9_]*\z/')]
        public readonly string $name,
        #[Rule('string')]
        public readonly ?string $label = null,
        #[ArrayOf(FieldData::class)]
        #[Rule('array')]
        public readonly array $fields = [],
        #[Rule('array')]
        public readonly array $access = [],
        #[Rule('array')]
        public readonly array $field_order = [],
    ) {
    }

    /** @return array<string, mixed> the CollectionManager::create() payload */
    public function toPayload(): array
    {
        $payload = [
            'name'   => $this->name,
            'fields' => array_map(static fn (FieldData $f): array => $f->toArray(), $this->fields),
        ];
        if ($this->label !== null && $this->label !== '') {
            $payload['label'] = $this->label;
        }
        if ($this->access !== []) {
            $payload['access'] = $this->access;
        }
        if ($this->field_order !== []) {
            $payload['field_order'] = $this->field_order;
        }

        return $payload;
    }
}
