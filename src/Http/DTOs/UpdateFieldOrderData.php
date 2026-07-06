<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for replacing a collection's field display order
 * (`PATCH /v1/admin/collections/{name}/field-order`).
 *
 * @param list<string> $field_order all column names (system + custom) in display order
 */
final class UpdateFieldOrderData implements RequestData
{
    /** @param list<string> $field_order */
    public function __construct(
        #[Rule('array')]
        public readonly array $field_order = [],
    ) {
    }
}
