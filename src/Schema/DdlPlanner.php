<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

use Thallo\Collections\Exceptions\BlockedSchemaChangeException;

/**
 * Pure-logic DDL planner: diffs a CollectionDefinition against its current state
 * and emits the list of SchemaChange operations required to converge the schema.
 *
 * Rules (v1):
 *  - planCreate emits one `create_table` op followed by an `add_index` op per indexed field.
 *  - planAlter diffs field lists by name:
 *      · name only in $next       → add_field (destructive: false) + its add_index ops
 *      · name only in $current    → drop_field (destructive: true)
 *      · same name, changed storage signature → throws BlockedSchemaChangeException.
 *        Storage signature = type + (nullable, length, precision, scale, bigint, target, multi).
 *        `index` and `unique` are explicitly excluded and diffed separately (see below).
 *      · same name, only index/unique changed → add_index / drop_index (not blocked)
 *  - Adding a unique index to an EXISTING field (false→true) is flagged destructive: true
 *    (needs data pre-flight); on a brand-new column the pre-flight is trivially satisfied.
 *  - Indexes are ALWAYS separate ops, never inline column modifiers. Inline unique at
 *    CREATE TABLE becomes a Postgres table CONSTRAINT that `dropUnique` (DROP INDEX)
 *    cannot remove, and inline plain indexes are silently discarded by the create-table
 *    SQL generator. Routing every index through the alter path yields real, droppable
 *    CREATE [UNIQUE] INDEX artifacts with the framework's auto-names.
 */
final class DdlPlanner
{
    /**
     * Plan the operations required to create a collection's table from scratch:
     * the create_table op, then one add_index op per indexed field.
     *
     * @return list<SchemaChange>
     */
    public function planCreate(CollectionDefinition $definition): array
    {
        $ops = [new SchemaChange('create_table', null, false)];

        foreach ($definition->fields as $field) {
            $ops = array_merge($ops, $this->indexOpsForNewField($field));
        }

        return $ops;
    }

    /**
     * Plan the operations required to evolve an existing table from $current to $next.
     *
     * @return list<SchemaChange>
     *
     * @throws BlockedSchemaChangeException when a field present in both definitions has a
     *                                       changed storage signature (type, nullable, length,
     *                                       precision, scale, bigint, target, or multi).
     *                                       In-place column changes are not supported in v1;
     *                                       callers must drop + re-add the field instead.
     */
    public function planAlter(CollectionDefinition $current, CollectionDefinition $next): array
    {
        /** @var array<string, CollectionField> $currentMap */
        $currentMap = [];
        foreach ($current->fields as $field) {
            $currentMap[$field->name] = $field;
        }

        /** @var array<string, CollectionField> $nextMap */
        $nextMap = [];
        foreach ($next->fields as $field) {
            $nextMap[$field->name] = $field;
        }

        // Detect any storage-signature change before emitting ops — fail fast on blocked changes.
        foreach ($nextMap as $name => $nextField) {
            if (!isset($currentMap[$name])) {
                continue;
            }
            $currentField = $currentMap[$name];
            $currentSig   = $this->storageSignature($currentField);
            $nextSig      = $this->storageSignature($nextField);

            if ($currentSig === $nextSig) {
                continue;
            }

            if ($currentField->type !== $nextField->type) {
                throw BlockedSchemaChangeException::retype($name, $currentField->type, $nextField->type);
            }

            throw BlockedSchemaChangeException::storageSignatureChanged($name, $currentSig, $nextSig);
        }

        $ops = [];

        // Fields removed in $next → destructive drop.
        foreach ($currentMap as $name => $field) {
            if (!isset($nextMap[$name])) {
                $ops[] = new SchemaChange('drop_field', $field, true);
            }
        }

        // Fields added in $next → non-destructive add, plus the new column's index ops.
        foreach ($nextMap as $name => $field) {
            if (!isset($currentMap[$name])) {
                $ops[] = new SchemaChange('add_field', $field, false);
                $ops   = array_merge($ops, $this->indexOpsForNewField($field));
            }
        }

        // Index changes on fields present in both definitions.
        foreach ($nextMap as $name => $nextField) {
            if (!isset($currentMap[$name])) {
                continue; // already handled as add_field above
            }
            $currentField = $currentMap[$name];

            $ops = array_merge($ops, $this->diffIndexOps($currentField, $nextField));
        }

        return $ops;
    }

    /**
     * Build a normalized storage-signature array for a field.
     *
     * Covers every setting that defines the physical column or relation semantics.
     * `index` and `unique` are intentionally excluded — those are diffed separately
     * as index ops and are never a reason to block an alter.
     *
     * Defaults:
     *   nullable → true  (most columns are nullable unless explicitly set otherwise)
     *   multi    → false
     *   All others → null when absent (no opinion)
     *
     * @return array<string, mixed>
     */
    private function storageSignature(CollectionField $field): array
    {
        $s = $field->settings;

        return [
            'type'      => $field->type,
            'nullable'  => isset($s['nullable']) ? (bool) $s['nullable'] : true,
            'length'    => $s['length'] ?? null,
            'precision' => $s['precision'] ?? null,
            'scale'     => $s['scale'] ?? null,
            'bigint'    => (bool) ($s['bigint'] ?? false),
            'target'    => $s['target'] ?? null,
            'multi'     => (bool) ($s['multi'] ?? false),
        ];
    }

    /**
     * The add_index ops for a brand-new column, applying the same effective-plain rule
     * as {@see self::diffIndexOps}: a unique constraint already serves lookups, so the
     * plain index is only created when the field is not unique. Not flagged destructive —
     * a freshly added column trivially passes the unique pre-flight.
     *
     * @return list<SchemaChange>
     */
    private function indexOpsForNewField(CollectionField $field): array
    {
        if (!empty($field->settings['unique'])) {
            return [new SchemaChange('add_index', $field, false, 'unique')];
        }
        if (!empty($field->settings['index'])) {
            return [new SchemaChange('add_index', $field, false, 'plain')];
        }

        return [];
    }

    /**
     * Produce add_index / drop_index ops for a field whose storage signature is unchanged.
     *
     * Each op carries its `indexKind` ('unique' | 'plain') fixed at plan time — the
     * materializer executes exactly the kind planned and never re-derives it from the
     * field's post-change settings.
     *
     * A unique constraint already serves lookups, so a plain index on the same column is
     * physically redundant — and the framework's column builder skips creating one when
     * the column is unique. The planner mirrors that rule: the EFFECTIVE plain index is
     * `index && !unique`, on both sides of the diff. This keeps the physical state and
     * the plan in agreement across every transition (e.g. dropping `unique` on a field
     * whose settings keep `index: true` re-creates the plain index; adding `unique` to a
     * plainly-indexed field drops the now-redundant plain index).
     *
     * @return list<SchemaChange>
     */
    private function diffIndexOps(CollectionField $current, CollectionField $next): array
    {
        $ops = [];

        $currentUnique = !empty($current->settings['unique']);
        $nextUnique    = !empty($next->settings['unique']);

        if (!$currentUnique && $nextUnique) {
            // Adding a unique constraint requires a data pre-flight (destructive: true).
            $ops[] = new SchemaChange('add_index', $next, true, 'unique');
        } elseif ($currentUnique && !$nextUnique) {
            $ops[] = new SchemaChange('drop_index', $next, false, 'unique');
        }

        $currentPlain = !empty($current->settings['index']) && !$currentUnique;
        $nextPlain    = !empty($next->settings['index']) && !$nextUnique;

        if (!$currentPlain && $nextPlain) {
            $ops[] = new SchemaChange('add_index', $next, false, 'plain');
        } elseif ($currentPlain && !$nextPlain) {
            $ops[] = new SchemaChange('drop_index', $next, false, 'plain');
        }

        return $ops;
    }
}
