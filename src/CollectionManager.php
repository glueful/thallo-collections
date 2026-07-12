<?php

declare(strict_types=1);

namespace Thallo\Collections;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionUpdated;
use Thallo\Collections\Exceptions\CollectionValidationException;
use Thallo\Collections\Exceptions\ConcurrentSchemaChangeException;
use Thallo\Collections\Exceptions\DestructiveConfirmationRequiredException;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\AccessPolicy;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionField;
use Thallo\Collections\Schema\CollectionPhysicalName;
use Thallo\Collections\Schema\ColumnMapper;
use Thallo\Collections\Schema\DdlPlanner;
use Thallo\Collections\Schema\SchemaChange;
use Thallo\Collections\Schema\SchemaMaterializer;
use Thallo\Collections\Support\PublicId;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Orchestrates the full lifecycle of a collection: create, evolve (add field / add index /
 * remove index), and guarded destruction (drop field / drop collection).
 *
 * ## Table name derivation
 *
 * Physical table names are the collection name with a short, readable prefix:
 *   table_name = 'coll_' . $name        (e.g. 'posts' -> 'coll_posts')
 *
 * Names are validated as safe identifiers ([a-z][a-z0-9_]*) and capped (MAX_NAME_LENGTH) so the
 * table name stays within the 63-char identifier limit. The 'coll_' prefix groups collection
 * tables together and keeps them distinct from the pack's own 'collection_*' metadata tables
 * (collection_definitions, collection_schema_changes).
 *
 * ## Empty-table light path for destructive operations
 *
 * dropField() and dropCollection() require an explicit confirmation token when the data
 * table is non-empty.  When the table has zero rows, the confirmation is waived — this
 * avoids friction during schema iteration before any data has been ingested.
 *
 * ## schema_version
 *
 * Every accepted change bumps schema_version by 1. The version is stored on the
 * CollectionDefinition row and serves as a lightweight audit signal.
 *
 * ## What this manager does NOT do
 *
 * It is never invoked for capability enable/disable.  Migrations and capability
 * registration are handled by CollectionsServiceProvider.
 */
final class CollectionManager
{
    private const MAX_NAME_LENGTH = 64;

    /**
     * System columns added to every collection table by SchemaMaterializer.
     * Field names that collide with these must be rejected at the create() gate.
     *
     * @var list<string>
     */
    private const SYSTEM_COLUMNS = [
        'id',
        'uuid',
        'created_at',
        'updated_at',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    /**
     * Curated common-subset SQL reserved words that must not be used as field names.
     *
     * This is not an exhaustive ANSI SQL catalogue — it covers the keywords most likely
     * to cause query-builder or DDL conflicts. Field names that match (case-insensitively)
     * are rejected with a clear validation error.
     *
     * @var list<string>
     */
    private const SQL_KEYWORDS = [
        'select', 'from', 'where', 'order', 'group', 'table', 'index', 'key',
        'default', 'drop', 'alter', 'create', 'insert', 'update', 'delete',
        'join', 'on', 'as', 'in', 'and', 'or', 'not', 'null', 'primary',
        'unique', 'foreign', 'references', 'constraint', 'column', 'values',
        'set', 'into', 'distinct', 'having', 'limit', 'offset', 'union',
        'like', 'between', 'exists', 'case', 'when', 'then', 'else', 'end',
        'asc', 'desc', 'all', 'any', 'true', 'false',
    ];

    public function __construct(
        private readonly CollectionDefinitionRepository $repo,
        private readonly DdlPlanner $planner,
        private readonly SchemaMaterializer $materializer,
        private readonly Connection $connection,
        private readonly ColumnMapper $columnMapper,
        private readonly EventService $events,
        private readonly SingleStoreTenant $tenant,
    ) {
    }

    /**
     * Create a new collection: validate, persist the definition, and materialize the table.
     *
     * @param array<string, mixed> $payload
     *   Required keys: name (string), fields (list<array>)
     *   Optional keys: label (string), storage_mode (string — only 'table' accepted)
     *
     * @throws CollectionValidationException when name format is invalid, storage_mode is
     *                                        not 'table', or any field name conflicts with
     *                                        a system column.
     */
    public function create(array $payload, string $actorType, ?string $actorId): CollectionDefinition
    {
        $this->validateCreate($payload);

        $tenantUuid = $this->tenant->resolve();
        $name = (string) $payload['name'];
        $label = isset($payload['label']) ? (string) $payload['label'] : $this->deriveLabel($name);
        $uuid = PublicId::generate('col');

        /** @var list<CollectionField> $fields */
        $fields = array_values(array_map(
            static fn (array $f): CollectionField => CollectionField::fromArray($f),
            (array) ($payload['fields'] ?? []),
        ));

        $accessPolicy = isset($payload['access']) && is_array($payload['access'])
            ? AccessPolicy::fromArray($payload['access'])
            : AccessPolicy::default();

        $fieldOrder = isset($payload['field_order']) && is_array($payload['field_order'])
            ? array_values(array_filter($payload['field_order'], 'is_string'))
            : [];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $def = new CollectionDefinition(
                uuid: $uuid,
                tenantUuid: $tenantUuid,
                name: $name,
                label: $label,
                tableName: CollectionPhysicalName::generate($tenantUuid),
                storageMode: 'table',
                fields: $fields,
                schemaVersion: 1,
                status: 'active',
                accessPolicy: $accessPolicy,
                fieldOrder: $fieldOrder,
            );

            try {
                // A fresh transaction per attempt keeps a table-name collision from poisoning
                // the next PostgreSQL attempt. Definition + DDL always commit together.
                $this->connection->transaction(function () use ($def, $actorType, $actorId): void {
                    $this->repo->insert($def);
                    $this->materializer->apply(
                        $def,
                        $this->planner->planCreate($def),
                        $actorType,
                        $actorId,
                    );
                });
            } catch (\PDOException $e) {
                if (
                    $e->getCode() === '23505'
                    && str_contains($e->getMessage(), 'uniq_collection_def_table_name')
                    && $attempt < 5
                ) {
                    continue;
                }
                throw $e;
            }

            $this->events->dispatch(new CollectionCreated($name, $actorType, $actorId, $tenantUuid));
            return $def;
        }

        throw new \RuntimeException('Could not allocate a unique collection physical table name.');
    }

    /**
     * Add a field to an existing collection.
     *
     * @param array<string, mixed> $field  Field descriptor (name, type, settings).
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function addField(
        string $name,
        array $field,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current   = $this->loadOrFail($name);
        $fieldName = isset($field['name']) ? (string) $field['name'] : '';

        $fieldErrors = $this->validateFieldSpec($field, $current->tableName);
        if ($fieldErrors === [] && $this->findField($current, $fieldName) !== null) {
            $fieldErrors['name'] = sprintf(
                "Field '%s' already exists on collection '%s'.",
                $fieldName,
                $name,
            );
        }
        if ($fieldErrors !== []) {
            throw CollectionValidationException::make($fieldErrors);
        }

        $newField = CollectionField::fromArray($field);

        $next = $this->rebuildWith($current, [...$current->fields, $newField]);
        $ops  = $this->planner->planAlter($current, $next);

        $this->commitAlter($current, $next, $ops, $actorType, $actorId);

        $this->events->dispatch(new CollectionUpdated(
            $name,
            'field_added',
            $fieldName,
            $actorType,
            $actorId,
            $current->tenantUuid,
        ));

        return $next;
    }

    /**
     * Add an index (plain or unique) to an existing field.
     *
     * @param array<string, mixed> $settings  Index settings to merge into the field
     *                                         (e.g. ['index' => true] or ['unique' => true]).
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function addIndex(
        string $name,
        string $field,
        array $settings,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);
        $this->fieldOrFail($current, $field);

        $updatedFields = array_map(
            static fn (CollectionField $f): CollectionField => $f->name === $field
                ? new CollectionField($f->name, $f->type, array_merge($f->settings, $settings))
                : $f,
            $current->fields,
        );

        $next = $this->rebuildWith($current, array_values($updatedFields));
        $ops  = $this->planner->planAlter($current, $next);

        if ($ops === []) {
            // Nothing to do physically — bumping schema_version and dispatching an
            // index_added event for a change that never happened would corrupt the audit.
            throw CollectionValidationException::make(['index' => sprintf(
                "Field '%s' already carries the requested index"
                . ' (a unique constraint also serves lookups, so a separate plain index is never created).',
                $field,
            )]);
        }

        $this->commitAlter($current, $next, $ops, $actorType, $actorId);

        $this->events->dispatch(new CollectionUpdated(
            $name,
            'index_added',
            $field,
            $actorType,
            $actorId,
            $current->tenantUuid,
        ));

        return $next;
    }

    /**
     * Remove an index (plain or unique) from an existing field.
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function removeIndex(
        string $name,
        string $field,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);
        $this->fieldOrFail($current, $field);

        $indexKeys     = ['index' => true, 'unique' => true];
        $updatedFields = array_map(
            static fn (CollectionField $f): CollectionField => $f->name === $field
                ? new CollectionField(
                    $f->name,
                    $f->type,
                    array_diff_key($f->settings, $indexKeys),
                )
                : $f,
            $current->fields,
        );

        $next = $this->rebuildWith($current, array_values($updatedFields));
        $ops  = $this->planner->planAlter($current, $next);

        if ($ops === []) {
            throw CollectionValidationException::make(['index' => sprintf(
                "Field '%s' has no indexes to remove.",
                $field,
            )]);
        }

        $this->commitAlter($current, $next, $ops, $actorType, $actorId);

        $this->events->dispatch(new CollectionUpdated(
            $name,
            'index_removed',
            $field,
            $actorType,
            $actorId,
            $current->tenantUuid,
        ));

        return $next;
    }

    /**
     * Drop a field from an existing collection.
     *
     * When the data table has existing rows, $opts['confirm'] must equal $field exactly.
     * When the table is empty the confirmation is waived (empty-table light path).
     *
     * @param array<string, mixed> $opts  Pass ['confirm' => $field] to acknowledge data loss.
     *
     * @throws DestructiveConfirmationRequiredException when the table has rows and
     *                                                   confirmation is absent or wrong.
     * @throws \DomainException when no collection with $name exists.
     */
    public function dropField(
        string $name,
        string $field,
        array $opts,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);
        $this->fieldOrFail($current, $field);

        if (!$this->isTableEmpty($current->tableName)) {
            $confirm = $opts['confirm'] ?? null;
            if ($confirm !== $field) {
                throw DestructiveConfirmationRequiredException::forField($field, $name);
            }
        }

        $updatedFields = array_values(array_filter(
            $current->fields,
            static fn (CollectionField $f): bool => $f->name !== $field,
        ));

        $next = $this->rebuildWith($current, $updatedFields);
        $ops  = $this->planner->planAlter($current, $next);

        $this->commitAlter($current, $next, $ops, $actorType, $actorId);

        $this->events->dispatch(new CollectionUpdated(
            $name,
            'field_dropped',
            $field,
            $actorType,
            $actorId,
            $current->tenantUuid,
        ));

        return $next;
    }

    /**
     * Drop an entire collection: verify confirmation, drop the physical table, delete the
     * definition row.
     *
     * When the data table has existing rows, $opts['confirm'] must equal $name exactly.
     * When the table is empty the confirmation is waived (empty-table light path).
     *
     * @param array<string, mixed> $opts  Pass ['confirm' => $name] to acknowledge data loss.
     *
     * @throws DestructiveConfirmationRequiredException when the table has rows and
     *                                                   confirmation is absent or wrong.
     * @throws \DomainException when no collection with $name exists.
     */
    public function dropCollection(
        string $name,
        array $opts,
        string $actorType,
        ?string $actorId,
    ): void {
        $current = $this->loadOrFail($name);

        if (!$this->isTableEmpty($current->tableName)) {
            $confirm = $opts['confirm'] ?? null;
            if ($confirm !== $name) {
                throw DestructiveConfirmationRequiredException::forCollection($name);
            }
        }

        // Definition delete + DROP TABLE in one transaction: a failure in either rolls
        // back both, so the definition can never point at a missing table (which would
        // also break the isTableEmpty() guard on a retry).
        $this->connection->transaction(function () use ($current, $actorType, $actorId): void {
            $this->repo->delete($current->uuid);
            $this->materializer->apply(
                $current,
                [new SchemaChange('drop_table', null, true)],
                $actorType,
                $actorId,
            );
        });

        $this->events->dispatch(new CollectionDropped($name, $actorType, $actorId, $current->tenantUuid));
    }

    /**
     * Replace a collection's access policy. Metadata only — no table DDL, no schema-version
     * bump — but fully audited: this is the mutation that can make a collection
     * world-readable/writable, so it stamps the actor on a schema-change audit row and
     * dispatches a CollectionUpdated event like every structural change.
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function setAccessPolicy(
        string $name,
        AccessPolicy $policy,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);
        $next    = new CollectionDefinition(
            uuid: $current->uuid,
            tenantUuid: $current->tenantUuid,
            name: $current->name,
            label: $current->label,
            tableName: $current->tableName,
            storageMode: $current->storageMode,
            fields: $current->fields,
            schemaVersion: $current->schemaVersion,
            status: $current->status,
            accessPolicy: $policy,
            fieldOrder: $current->fieldOrder,
        );

        $now = date('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($current, $next, $policy, $actorType, $actorId, $now): void {
            $this->repo->update($next);
            $this->connection->table('collection_schema_changes')->insert([
                'uuid'            => PublicId::generate('sc'),
                'tenant_uuid'     => $current->tenantUuid,
                'collection_uuid' => $current->uuid,
                'change_type'     => 'update_access',
                'payload'         => (string) json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
                'actor_type'      => $actorType,
                'actor_id'        => $actorId,
                'destructive'     => 0,
                'status'          => 'applied',
                'created_at'      => $now,
                'applied_at'      => $now,
            ]);
        });

        $this->events->dispatch(new CollectionUpdated(
            $name,
            'access_updated',
            null,
            $actorType,
            $actorId,
            $current->tenantUuid,
        ));

        return $next;
    }

    /**
     * Replace the display order of all columns (system + custom). Metadata only — no DDL.
     *
     * @param list<string> $order
     */
    public function setFieldOrder(string $name, array $order): CollectionDefinition
    {
        $current = $this->loadOrFail($name);
        $next    = new CollectionDefinition(
            uuid: $current->uuid,
            tenantUuid: $current->tenantUuid,
            name: $current->name,
            label: $current->label,
            tableName: $current->tableName,
            storageMode: $current->storageMode,
            fields: $current->fields,
            schemaVersion: $current->schemaVersion,
            status: $current->status,
            accessPolicy: $current->accessPolicy,
            fieldOrder: array_values(array_filter($order, 'is_string')),
        );
        $this->repo->update($next);

        return $next;
    }

    // ----------------------------------------------------------------- private

    /**
     * @param array<string, mixed> $payload
     *
     * @throws CollectionValidationException
     */
    private function validateCreate(array $payload): void
    {
        $errors = [];

        // storage_mode: absent defaults to 'table'; any other value is rejected.
        $storageMode = isset($payload['storage_mode']) ? (string) $payload['storage_mode'] : 'table';
        if ($storageMode !== 'table') {
            $errors['storage_mode'] = 'Only table storage is supported in v1.';
        }

        // name: required, must match the safe-identifier pattern.
        $name = isset($payload['name']) ? (string) $payload['name'] : '';
        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $errors['name'] = 'Name must start with a lowercase letter and contain only [a-z0-9_].';
        } elseif (strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = sprintf('Name must be at most %d characters.', self::MAX_NAME_LENGTH);
        }

        // Fail early on storage_mode / name errors before inspecting fields.
        if ($errors !== []) {
            throw CollectionValidationException::make($errors);
        }

        // A taken name is a validation error, not a raw unique-constraint 500. The DB
        // constraint still backs the rare create/create race.
        if ($this->repo->findByName($name) !== null) {
            throw CollectionValidationException::make([
                'name' => sprintf("A collection named '%s' already exists.", $name),
            ]);
        }

        // fields: full per-field validation plus duplicate-name detection across the payload.
        $tableName = CollectionPhysicalName::generate($this->tenant->resolve());
        $seen      = [];
        foreach ((array) ($payload['fields'] ?? []) as $i => $fieldData) {
            foreach ($this->validateFieldSpec((array) $fieldData, $tableName) as $key => $message) {
                $errors["fields.{$i}.{$key}"] = $message;
            }

            $fieldName = isset($fieldData['name']) ? (string) $fieldData['name'] : '';
            if ($fieldName !== '' && isset($seen[$fieldName])) {
                $errors["fields.{$i}.name"] = sprintf("Duplicate field name '%s'.", $fieldName);
            }
            $seen[$fieldName] = true;
        }

        if ($errors !== []) {
            throw CollectionValidationException::make($errors);
        }
    }

    /**
     * Validate a full field descriptor (name, type, settings) for creation on $tableName.
     *
     * Shared by create() and addField(). Returns error messages keyed by the offending
     * part ('name', 'type', 'settings.length', ...); empty when the field is acceptable.
     *
     * @param array<string, mixed> $fieldData
     *
     * @return array<string, string>
     */
    private function validateFieldSpec(array $fieldData, string $tableName): array
    {
        $errors = [];

        $name  = isset($fieldData['name']) ? (string) $fieldData['name'] : '';
        $error = $this->validateFieldName($name);
        if ($error !== null) {
            $errors['name'] = $error;
        } elseif (strlen($tableName) + strlen($name) + 8 > 63) {
            // The longest derived identifier is the unique-index name
            // "{table}_{field}_unique"; PostgreSQL silently truncates identifiers at
            // 63 bytes, which would make two long names collide.
            $errors['name'] = sprintf(
                "Field name '%s' is too long: the derived index name '%s_%s_unique' must fit"
                . ' the 63-character identifier limit.',
                $name,
                $tableName,
                $name,
            );
        }

        $type = isset($fieldData['type']) ? (string) $fieldData['type'] : '';
        if (!in_array($type, $this->columnMapper->supportedTypes(), true)) {
            $errors['type'] = sprintf("Unsupported field type '%s'.", $type);
        }

        $s = isset($fieldData['settings']) && is_array($fieldData['settings']) ? $fieldData['settings'] : [];

        if (isset($s['length']) && ((int) $s['length'] < 1 || (int) $s['length'] > 10485760)) {
            $errors['settings.length'] = 'length must be between 1 and 10485760.';
        }

        if (isset($s['precision']) || isset($s['scale'])) {
            $precision = isset($s['precision']) ? (int) $s['precision'] : 10;
            $scale     = isset($s['scale']) ? (int) $s['scale'] : 2;
            if ($precision < 1 || $precision > 1000) {
                $errors['settings.precision'] = 'precision must be between 1 and 1000.';
            } elseif ($scale < 0 || $scale > $precision) {
                $errors['settings.scale'] = 'scale must be between 0 and the precision.';
            }
        }

        if ($type === 'collections.enum') {
            $values = $s['values'] ?? null;
            $strings = is_array($values) ? array_filter($values, 'is_string') : [];
            if (!is_array($values) || $values === [] || count($strings) !== count($values)) {
                // Without a values list the enum degrades to free text.
                $errors['settings.values'] = 'Enum fields require a non-empty settings.values list of strings.';
            }
        }

        return $errors;
    }

    /**
     * Derive a human-readable label from a snake_case collection name.
     */
    private function deriveLabel(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Physical table name for a collection: the name with the {@see self::TABLE_PREFIX} prefix.
     * Public + static so tests and tooling resolve the table name without re-deriving the scheme.
     */
    /**
     * Load a CollectionDefinition by name, throwing if it does not exist.
     *
     * @throws \DomainException
     */
    private function loadOrFail(string $name): CollectionDefinition
    {
        $def = $this->repo->findByName($name);
        if ($def === null) {
            throw new \DomainException(sprintf("Collection '%s' not found.", $name));
        }

        return $def;
    }

    /** The named field on the definition, or null. */
    private function findField(CollectionDefinition $def, string $field): ?CollectionField
    {
        foreach ($def->fields as $f) {
            if ($f->name === $field) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Assert the named field exists on the definition — a typo'd field name must 404,
     * not silently "succeed" while bumping schema_version and dispatching a phantom event.
     *
     * @throws \DomainException
     */
    private function fieldOrFail(CollectionDefinition $def, string $field): CollectionField
    {
        return $this->findField($def, $field) ?? throw new \DomainException(sprintf(
            "Field '%s' not found on collection '%s'.",
            $field,
            $def->name,
        ));
    }

    /**
     * Return true when the data table has zero rows.
     *
     * Used by the empty-table light path to waive destructive-op confirmation.
     */
    private function isTableEmpty(string $tableName): bool
    {
        return $this->connection->table($tableName)->count() === 0;
    }

    /**
     * Validate a single field name against format rules, system-column names, and SQL keywords.
     *
     * Returns a human-readable error message when the name is invalid, or null when acceptable.
     * The same check is applied by create() (via the field loop) and addField().
     */
    private function validateFieldName(string $name): ?string
    {
        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            return sprintf(
                "Field name '%s' must start with a lowercase letter and contain only [a-z0-9_].",
                $name,
            );
        }

        if (in_array($name, self::SYSTEM_COLUMNS, true)) {
            return sprintf("Field name '%s' conflicts with a system column.", $name);
        }

        if (in_array(strtolower($name), self::SQL_KEYWORDS, true)) {
            return sprintf("Field name '%s' is a reserved SQL keyword.", $name);
        }

        return null;
    }

    /**
     * Commit a planned schema alter: the version-guarded definition write and the DDL in
     * one transaction.
     *
     * The guarded UPDATE runs FIRST — it takes the definition row's lock, so concurrent
     * alters serialize here (the loser's WHERE re-evaluates against the committed row,
     * matches zero rows, and throws) before any DDL has run. A DDL failure afterwards
     * rolls back the definition write together with the DDL (transactional on PostgreSQL
     * and SQLite), so the definition and the physical table never diverge.
     *
     * @param list<SchemaChange> $ops
     *
     * @throws ConcurrentSchemaChangeException when another change won the version race.
     */
    private function commitAlter(
        CollectionDefinition $current,
        CollectionDefinition $next,
        array $ops,
        string $actorType,
        ?string $actorId,
    ): void {
        $this->connection->transaction(function () use ($current, $next, $ops, $actorType, $actorId): void {
            if ($this->repo->update($next, expectedSchemaVersion: $current->schemaVersion) === 0) {
                throw ConcurrentSchemaChangeException::forCollection($current->name);
            }
            $this->materializer->apply($next, $ops, $actorType, $actorId);
        });
    }

    /**
     * Build the "next" CollectionDefinition from the current one by replacing the field
     * list and bumping schema_version.
     *
     * @param list<CollectionField> $fields
     */
    private function rebuildWith(CollectionDefinition $current, array $fields): CollectionDefinition
    {
        return new CollectionDefinition(
            uuid: $current->uuid,
            tenantUuid: $current->tenantUuid,
            name: $current->name,
            label: $current->label,
            tableName: $current->tableName,
            storageMode: $current->storageMode,
            fields: $fields,
            schemaVersion: $current->schemaVersion + 1,
            status: $current->status,
            accessPolicy: $current->accessPolicy,
            fieldOrder: $current->fieldOrder,
        );
    }
}
