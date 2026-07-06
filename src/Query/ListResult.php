<?php

declare(strict_types=1);

namespace Thallo\Collections\Query;

/**
 * Readonly value object carrying the result of a QueryCompiler::list() call.
 *
 * @property list<array<string, mixed>> $data  Rows for the current page.
 * @property int                        $page     Requested page (1-based).
 * @property int                        $perPage  Effective per-page (after capping).
 * @property int                        $total    Total row count matching the filters (ignores pagination).
 */
final class ListResult
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }
}
