<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for replacing a collection's access policy
 * (`PATCH /v1/admin/collections/{name}/access`).
 *
 * A full replace: any operation omitted is normalized to the safe `scoped` default by AccessPolicy.
 */
final class UpdateAccessData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $read = null,
        #[Rule('string')]
        public readonly ?string $write = null,
        #[Rule('string')]
        public readonly ?string $delete = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(
            ['read' => $this->read, 'write' => $this->write, 'delete' => $this->delete],
            static fn (?string $v): bool => $v !== null,
        );
    }
}
