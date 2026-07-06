<?php

declare(strict_types=1);

namespace Thallo\Collections\Relations;

use Glueful\Database\Connection;
use Thallo\Collections\Exceptions\CollectionExpandForbiddenException;
use Thallo\Collections\Exceptions\RowReferencedException;
use Thallo\Collections\Exceptions\RowValidationException;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;

/**
 * Validates and resolves collection↔collection relation fields.
 *
 * ## Scope
 * Two target kinds are handled: `collection:{name}` (collection↔collection) and `users` (a
 * relation to the framework users table, exposing only safe columns — never the password). Any
 * other descriptor (e.g. a `content:*` target) is unsupported and silently skipped (no validation,
 * no expansion).
 *
 * ## Methods
 *
 * assertTargetsExist() — called by RowRepository before insert/update. For each referenced
 *   uuid in the value, it confirms the uuid exists in the target collection's table. Missing
 *   target → RowValidationException (reuses Task 8's exception class so the HTTP layer maps
 *   it to 422 uniformly).
 *
 * expand() — replaces relation field values with the resolved target rows. One level only;
 *   the expanded rows are returned as-is (their own relation fields remain raw uuids / JSON).
 *   Batch-loads to avoid N+1 queries within a single call.
 *
 * assertNotReferenced() — called by RowRepository before delete. Scans every collection
 *   definition for relation fields whose target is `collection:{target->name}`. For single
 *   relations it uses an equality check; for multi relations it searches the JSON-encoded
 *   array for the quoted uuid string (`LIKE '%"uuid"%'`). Throws RowReferencedException if
 *   any match is found.
 */
final class RelationResolver
{
    /** Relation target descriptor for the framework users table (`settings['target'] = "users"`). */
    private const USERS_TARGET = 'users';

    /**
     * Safe columns exposed when expanding a users relation on the public data API — enough to
     * render "created by / owner" style UI, and NEVER PII/operational state (no email, status,
     * or password). A privileged admin surface can project more if it ever needs to.
     */
    private const USERS_COLUMNS = ['uuid', 'username'];

    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionDefinitionRepository $definitions,
    ) {
    }

    /**
     * Assert that every uuid referenced by $value exists in the target collection's table.
     *
     * $value is the original (pre-coerce) input for the field:
     *   - single relation (multi=false) → a string uuid
     *   - multi relation (multi=true)   → a list<string> of uuids
     *
     * A null/empty value is allowed when the field is nullable.
     *
     * @param array<int, string>|string $value
     * @throws RowValidationException when any referenced uuid is absent.
     */
    public function assertTargetsExist(CollectionField $field, array|string $value): void
    {
        $target = $field->settings['target'] ?? '';

        if ($target === self::USERS_TARGET) {
            $this->assertUuidsExistIn($field, $value, 'users', 'users');
            return;
        }

        // Non-collection targets (e.g. content:*) are out of scope — skip silently.
        if (!str_starts_with($target, 'collection:')) {
            return;
        }

        $targetName = substr($target, strlen('collection:'));
        $targetDef  = $this->definitions->findByName($targetName);

        if ($targetDef === null) {
            // Unknown target collection — treat as a validation error so bad configs surface.
            throw RowValidationException::make([
                $field->name => sprintf(
                    "Field '%s' references an unknown collection '%s'.",
                    $field->name,
                    $targetName,
                ),
            ]);
        }

        $this->assertUuidsExistIn($field, $value, $targetDef->tableName, $targetName);
    }

    /**
     * Assert every referenced uuid exists in $table (single or multi value), else a 422-mapped error.
     *
     * @param array<int, string>|string $value
     * @throws RowValidationException
     */
    private function assertUuidsExistIn(
        CollectionField $field,
        array|string $value,
        string $table,
        string $label,
    ): void {
        $isMulti = !empty($field->settings['multi']);
        $uuids   = $isMulti ? (array) $value : [$value];

        // One batched lookup, not one COUNT per element — the element count is already
        // capped by RowValidator, so the IN list stays small.
        $uuids = array_values(array_unique(array_filter(
            $uuids,
            static fn (mixed $u): bool => is_string($u) && $u !== '',
        )));
        if ($uuids === []) {
            return;
        }

        $rows = $this->connection
            ->table($table)
            ->select(['uuid'])
            ->whereIn('uuid', $uuids)
            ->get();

        $missing = array_diff($uuids, array_column($rows, 'uuid'));
        if ($missing !== []) {
            throw RowValidationException::make([
                $field->name => sprintf(
                    "Field '%s' references a non-existent row uuid '%s' in '%s'.",
                    $field->name,
                    (string) reset($missing),
                    $label,
                ),
            ]);
        }
    }

    /**
     * Replace relation field values in $rows with the resolved target rows.
     *
     * Only fields named in $expand are processed; non-relation fields and unknown names are
     * skipped. The replacement is one level deep — the returned target rows are never
     * recursively expanded (their own relation fields remain as stored uuid strings / JSON).
     *
     * Batch-loads target rows to avoid N+1 queries: for each relation field being expanded
     * all referenced uuids across the entire $rows list are collected, fetched in a single
     * IN query, then distributed back.
     *
     * Targets with `content:*` descriptors are skipped (out of scope for v1).
     *
     * Authorization: a `collection:{name}` target is expanded only when `$canReadTarget` returns
     * true for the target's definition — the URL collection's own scope (checked by
     * CollectionScopeMiddleware) does NOT authorize reading a *different* collection's rows. An
     * explicit `?expand` of a forbidden target throws {@see CollectionExpandForbiddenException}
     * (mapped to 403 by the controller) rather than silently returning raw uuids.
     *
     * @param  list<array<string, mixed>> $rows          Rows from a collection's table.
     * @param  list<string>               $expand        Names of relation fields to expand.
     * @param  callable(CollectionDefinition): bool $canReadTarget target read-access predicate.
     * @return list<array<string, mixed>>                The same rows with relation values resolved.
     * @throws CollectionExpandForbiddenException when a requested collection target is unauthorized.
     */
    public function expand(CollectionDefinition $def, array $rows, array $expand, callable $canReadTarget): array
    {
        foreach ($expand as $fieldName) {
            $field = $def->field($fieldName);

            if ($field === null || $field->type !== 'collections.relation') {
                continue;
            }

            [$table, $columns] = $this->resolveTarget(
                (string) ($field->settings['target'] ?? ''),
                $fieldName,
                $canReadTarget,
            );
            if ($table === null) {
                continue; // unsupported / unknown target
            }

            $isMulti = !empty($field->settings['multi']);

            if ($isMulti) {
                $rows = $this->expandMulti($rows, $fieldName, $table, $columns);
            } else {
                $rows = $this->expandSingle($rows, $fieldName, $table, $columns);
            }
        }

        return $rows;
    }

    /**
     * Map a relation target descriptor to the table to read and the explicit, safe columns to
     * expose (never `SELECT *`). A `collection:` target must pass `$canReadTarget` first.
     *
     * @param callable(CollectionDefinition): bool $canReadTarget
     * @return array{0: string|null, 1: list<string>} [table, columns]; a null table means the
     *         target is unsupported/unknown and should be skipped.
     * @throws CollectionExpandForbiddenException when the caller can't read a collection target.
     */
    private function resolveTarget(string $target, string $fieldName, callable $canReadTarget): array
    {
        if ($target === self::USERS_TARGET) {
            return ['users', self::USERS_COLUMNS];
        }

        if (str_starts_with($target, 'collection:')) {
            $def = $this->definitions->findByName(substr($target, strlen('collection:')));
            if ($def === null) {
                return [null, []];
            }
            if (!$canReadTarget($def)) {
                throw CollectionExpandForbiddenException::forField($fieldName, $def->name);
            }
            return [$def->tableName, $this->safeColumns($def)];
        }

        return [null, []];
    }

    /**
     * The explicit column allow-list for an expanded collection row: the safe system columns plus
     * every declared field, excluding the internal auto-increment `id`. Field names can never
     * collide with a system column (CollectionManager rejects such names), so this is exhaustive.
     *
     * @return list<string>
     */
    private function safeColumns(CollectionDefinition $def): array
    {
        $columns = ['uuid', 'created_at', 'updated_at'];
        foreach ($def->fields as $field) {
            $columns[] = $field->name;
        }
        return array_values(array_unique($columns));
    }

    /**
     * Assert that no row in any collection references $uuid via a relation field whose
     * target is `collection:{$target->name}`.
     *
     * For single-relation columns the check is a simple equality match.
     * For multi-relation columns (JSON arrays) the check searches for the quoted uuid
     * string inside the JSON text (`LIKE '%"uuid"%'`), which is precise because:
     *   - UUIDs / Glueful row IDs never contain double-quote characters.
     *   - Each element of a JSON array of strings is always surrounded by `"` in the
     *     serialised representation, so `%"uuid"%` matches only an exact element,
     *     never a substring of a longer value.
     *
     * @throws RowReferencedException when any referencing row is found.
     */
    public function assertNotReferenced(CollectionDefinition $target, string $uuid): void
    {
        $allDefs = $this->definitions->all();

        foreach ($allDefs as $def) {
            foreach ($def->fields as $field) {
                if ($field->type !== 'collections.relation') {
                    continue;
                }

                $fieldTarget = $field->settings['target'] ?? '';
                if ($fieldTarget !== 'collection:' . $target->name) {
                    continue;
                }

                $isMulti = !empty($field->settings['multi']);

                if ($isMulti) {
                    // JSON array stored as text: search for the quoted uuid element.
                    // LIKE metacharacters must be escaped — row uuids are nanoids whose
                    // alphabet includes '_' (match-any-char), so an unescaped pattern can
                    // spuriously match a different stored uuid.
                    $count = $this->connection
                        ->table($def->tableName)
                        ->where($field->name, 'LIKE', '%"' . addcslashes($uuid, '\\%_') . '"%')
                        ->count();
                } else {
                    $count = $this->connection
                        ->table($def->tableName)
                        ->where($field->name, $uuid)
                        ->count();
                }

                if ($count > 0) {
                    throw RowReferencedException::forUuid(
                        $target->name,
                        $uuid,
                        $def->name,
                        $field->name,
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------

    /**
     * Expand a single-relation field across all rows in one batch load.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function expandSingle(array $rows, string $fieldName, string $table, ?array $columns): array
    {
        // Collect all unique referenced uuids across every row.
        $uuids = [];
        foreach ($rows as $row) {
            $raw = $row[$fieldName] ?? null;
            if (is_string($raw) && $raw !== '') {
                $uuids[$raw] = true;
            }
        }

        if ($uuids === []) {
            return $rows;
        }

        $query = $this->connection->table($table)->whereIn('uuid', array_keys($uuids));
        if ($columns !== null) {
            $query = $query->select($columns);
        }
        $targetRows = $query->get();

        /** @var array<string, array<string, mixed>> $byUuid */
        $byUuid = [];
        foreach ($targetRows as $tr) {
            $byUuid[(string) $tr['uuid']] = $tr;
        }

        foreach ($rows as &$row) {
            $raw = $row[$fieldName] ?? null;
            if (is_string($raw) && $raw !== '' && isset($byUuid[$raw])) {
                $row[$fieldName] = $byUuid[$raw];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Expand a multi-relation field (JSON-encoded uuid array) across all rows in one batch load.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function expandMulti(array $rows, string $fieldName, string $table, ?array $columns): array
    {
        // Collect all unique referenced uuids across every row.
        $uuids = [];
        foreach ($rows as $row) {
            $raw = $row[$fieldName] ?? null;
            if ($raw === null) {
                continue;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $u) {
                if (is_string($u) && $u !== '') {
                    $uuids[$u] = true;
                }
            }
        }

        if ($uuids === []) {
            return $rows;
        }

        $query = $this->connection->table($table)->whereIn('uuid', array_keys($uuids));
        if ($columns !== null) {
            $query = $query->select($columns);
        }
        $targetRows = $query->get();

        /** @var array<string, array<string, mixed>> $byUuid */
        $byUuid = [];
        foreach ($targetRows as $tr) {
            $byUuid[(string) $tr['uuid']] = $tr;
        }

        foreach ($rows as &$row) {
            $raw = $row[$fieldName] ?? null;
            if ($raw === null) {
                $row[$fieldName] = [];
                continue;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                $row[$fieldName] = [];
                continue;
            }
            // Elements are typed mixed, not string: legacy/hand-seeded JSON can hold
            // non-string members, which must be skipped — not a read-path TypeError.
            $row[$fieldName] = array_values(array_filter(
                array_map(
                    static fn (mixed $u): ?array => is_string($u) ? ($byUuid[$u] ?? null) : null,
                    $decoded,
                ),
                static fn (?array $v): bool => $v !== null,
            ));
        }
        unset($row);

        return $rows;
    }
}
