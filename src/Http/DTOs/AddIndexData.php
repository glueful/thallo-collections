<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for adding an index to a field (`POST /v1/admin/collections/{name}/indexes`).
 */
final class AddIndexData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $field,
        #[Rule('boolean')]
        public readonly bool $unique = false,
    ) {
    }

    /** @return array{unique: bool}|array{index: bool} the settings merged into the field */
    public function toSettings(): array
    {
        return $this->unique ? ['unique' => true] : ['index' => true];
    }
}
