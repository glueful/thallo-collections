<?php

declare(strict_types=1);

namespace Thallo\Collections\Query;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Thallo\Collections\Exceptions\InvalidQueryException;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Contracts\Schema\FieldTypeRegistry;

/**
 * Compiles a list query against a collection's materialized table.
 *
 * ## Supported params
 *
 *   filter   [field => [op => value]]
 *            ops: eq, ne, lt, lte, gt, gte, like, in, null
 *            'like'  → LIKE %value%  (bound as a parameter)
 *            'in'    → WHERE field IN (...)
 *            'null'  → truthy value → WHERE field IS NULL
 *                    → falsy value  → WHERE field IS NOT NULL
 *
 *   sort     'field' (ASC) or '-field' (DESC), comma-separated.
 *
 *   fields   CSV column projection. uuid is always included.
 *            System audit columns (id, created_at, updated_at, created_by_*, updated_by_*)
 *            are allowed in projections.
 *
 *   page     1-based page number (default 1).
 *   perPage  Rows per page (default: config thallo.collections.default_per_page, 20);
 *            capped at config thallo.collections.max_per_page (100).
 *
 * ## Filterable / sortable decisions
 *
 * User-defined fields: delegated to FieldTypeRegistry — capabilities()['filterable'] /
 * capabilities()['sortable']. For example, 'collections.text' and 'collections.json'
 * are NOT filterable or sortable per their registry definitions.
 *
 * System columns uuid, created_at, updated_at: always filterable and sortable.
 * Other system columns (id, created_by_*, updated_by_*): allowed in projections only.
 *
 * ## Security
 *
 * All filter values are bound as PDO parameters. Column/field names are whitelisted
 * against the CollectionDefinition before being placed in SQL.
 */
final class QueryCompiler
{
    /**
     * System columns that are always allowed as filter and sort targets.
     *
     * Rationale: uuid enables direct lookup; created_at / updated_at enable time-range
     * queries and chronological sorting — both are common API patterns that don't require
     * a user-defined field.  Other system columns (id, created_by_*) are intentionally
     * excluded to keep the public API surface minimal.
     *
     * @var list<string>
     */
    private const FILTERABLE_SYSTEM_COLS = ['uuid', 'created_at', 'updated_at'];

    /**
     * Full set of system columns allowed in a SELECT projection.
     *
     * @var list<string>
     */
    private const PROJECTION_SYSTEM_COLS = [
        'id',
        'uuid',
        'created_at',
        'updated_at',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FieldTypeRegistry $registry,
        private readonly ApplicationContext $context,
    ) {
    }

    /**
     * Execute a list query against the collection's real table.
     *
     * @param array<string, mixed> $params
     * @throws InvalidQueryException for unknown fields or non-filterable/sortable types.
     */
    public function list(CollectionDefinition $def, array $params): ListResult
    {
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = $this->resolvePerPage($params['perPage'] ?? null);

        $qb = $this->connection->table($def->tableName);

        // --- SELECT projection ---
        // Array-valued params (?fields[]=x) would fatally string-cast — reject as 422s.
        if (isset($params['fields']) && !is_scalar($params['fields'])) {
            throw InvalidQueryException::malformedParam('fields', 'a comma-separated string');
        }
        $select = $this->resolveSelect($def, isset($params['fields']) ? (string) $params['fields'] : null);
        $qb->select($select);

        // --- Filters ---
        $filters = isset($params['filter']) ? (array) $params['filter'] : [];
        foreach ($filters as $field => $ops) {
            $field = (string) $field;
            $this->assertFilterable($def, $field);
            foreach ((array) $ops as $op => $value) {
                $this->applyFilter($qb, $field, (string) $op, $value);
            }
        }

        // --- Sort ---
        if (isset($params['sort']) && !is_scalar($params['sort'])) {
            throw InvalidQueryException::malformedParam('sort', 'a comma-separated string');
        }
        $sortParam = isset($params['sort']) ? trim((string) $params['sort']) : '';
        if ($sortParam !== '') {
            foreach (explode(',', $sortParam) as $sortToken) {
                $sortToken = trim($sortToken);
                if ($sortToken === '') {
                    continue;
                }
                $desc = str_starts_with($sortToken, '-');
                $col  = $desc ? substr($sortToken, 1) : $sortToken;
                $this->assertSortable($def, $col);
                $qb->orderBy($col, $desc ? 'DESC' : 'ASC');
            }
        }

        // Offset pagination via the framework query builder: it runs the COUNT and the
        // LIMIT/OFFSET page fetch (honouring the where/orderBy/select already applied) in one call.
        /** @var array{data:list<array<string,mixed>>,total:int,current_page:int,per_page:int} $result */
        $result = $qb->paginate($page, $perPage);

        return new ListResult(
            data: array_values($result['data']),
            page: $page,
            perPage: $perPage,
            total: $result['total'],
        );
    }

    // ------------------------------------------------------------------

    /**
     * Resolve the effective per-page value, applying the configured cap.
     */
    private function resolvePerPage(mixed $requested): int
    {
        $default = (int) config($this->context, 'thallo.collections.default_per_page', 20);
        $max     = (int) config($this->context, 'thallo.collections.max_per_page', 100);

        $perPage = ($requested === null || $requested === '') ? $default : (int) $requested;

        return min(max(1, $perPage), $max);
    }

    /**
     * Build the SELECT column list from the 'fields' param.
     * uuid is always included. Unknown fields throw InvalidQueryException.
     *
     * @return list<string>
     */
    private function resolveSelect(CollectionDefinition $def, ?string $fields): array
    {
        if ($fields === null || trim($fields) === '') {
            return ['*'];
        }

        $allowed = $this->allProjectionColumns($def);
        $cols    = ['uuid']; // always present

        foreach (explode(',', $fields) as $col) {
            $col = trim($col);
            if ($col === '') {
                continue;
            }
            if (!in_array($col, $allowed, true)) {
                throw InvalidQueryException::unknownField($col, 'fields');
            }
            if (!in_array($col, $cols, true)) {
                $cols[] = $col;
            }
        }

        return $cols;
    }

    /**
     * Assert that a field name is allowed as a filter target.
     *
     * @throws InvalidQueryException
     */
    private function assertFilterable(CollectionDefinition $def, string $field): void
    {
        if (in_array($field, self::FILTERABLE_SYSTEM_COLS, true)) {
            return;
        }

        $colField = $def->field($field);
        if ($colField === null) {
            throw InvalidQueryException::unknownField($field, 'filter');
        }

        if (!$this->registry->has($colField->type)) {
            throw InvalidQueryException::unknownField($field, 'filter');
        }

        $caps = $this->registry->get($colField->type)->capabilities();
        if (empty($caps['filterable'])) {
            throw InvalidQueryException::nonFilterableField($field, $colField->type);
        }
    }

    /**
     * Assert that a field name is allowed as a sort target.
     *
     * @throws InvalidQueryException
     */
    private function assertSortable(CollectionDefinition $def, string $field): void
    {
        if (in_array($field, self::FILTERABLE_SYSTEM_COLS, true)) {
            return;
        }

        $colField = $def->field($field);
        if ($colField === null) {
            throw InvalidQueryException::unknownField($field, 'sort');
        }

        if (!$this->registry->has($colField->type)) {
            throw InvalidQueryException::unknownField($field, 'sort');
        }

        $caps = $this->registry->get($colField->type)->capabilities();
        if (empty($caps['sortable'])) {
            throw InvalidQueryException::nonSortableField($field, $colField->type);
        }
    }

    /**
     * Apply a single filter operator clause to the query builder.
     *
     * All values are passed as bound parameters; only whitelisted column names reach SQL.
     *
     * @throws InvalidQueryException for unknown operators.
     */
    private function applyFilter(QueryBuilder $qb, string $field, string $op, mixed $value): void
    {
        $scalarOps = ['eq' => '=', 'ne' => '!=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];

        if (isset($scalarOps[$op])) {
            if (!is_scalar($value) && $value !== null) {
                throw InvalidQueryException::malformedParam("filter[{$field}][{$op}]", 'a scalar value');
            }
            $qb->where($field, $scalarOps[$op], $value);
        } elseif ($op === 'like') {
            if (!is_scalar($value)) {
                throw InvalidQueryException::malformedParam("filter[{$field}][like]", 'a scalar value');
            }
            $qb->whereLike($field, '%' . (string) $value . '%');
        } elseif ($op === 'in') {
            $values = array_values(array_filter((array) $value, 'is_scalar'));
            if ($values === []) {
                throw InvalidQueryException::malformedParam(
                    "filter[{$field}][in]",
                    'a non-empty list of scalar values',
                );
            }
            $qb->whereIn($field, $values);
        } elseif ($op === 'null') {
            // Truthiness would read the string "false" as true and invert the intent.
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                $qb->whereNull($field);
            } else {
                $qb->whereNotNull($field);
            }
        } else {
            throw InvalidQueryException::unknownOperator($op, $field);
        }
    }

    /**
     * Returns all column names that are valid in a SELECT projection for this collection.
     *
     * @return list<string>
     */
    private function allProjectionColumns(CollectionDefinition $def): array
    {
        $cols = self::PROJECTION_SYSTEM_COLS;
        foreach ($def->fields as $field) {
            $cols[] = $field->name;
        }
        return $cols;
    }
}
