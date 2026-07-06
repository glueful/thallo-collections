<?php

declare(strict_types=1);

namespace Thallo\Collections\Repositories;

use Glueful\Database\Connection;
use Thallo\Collections\Schema\CollectionDefinition;

/**
 * CRUD gateway for the collection_definitions table.
 *
 * All methods operate on the injected Connection; no ApplicationContext is required
 * because the pack's services() wires the Connection singleton from the framework DI.
 */
final class CollectionDefinitionRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Persist a new CollectionDefinition row.
     */
    public function insert(CollectionDefinition $def): void
    {
        $now = date('Y-m-d H:i:s');

        $this->connection->table('collection_definitions')->insert([
            'uuid'           => $def->uuid,
            'name'           => $def->name,
            'label'          => $def->label,
            'table_name'     => $def->tableName,
            'storage_mode'   => $def->storageMode,
            'fields'         => $this->serializeFields($def),
            'schema_version' => $def->schemaVersion,
            'status'         => $def->status,
            'access_policy'  => (string) json_encode($def->accessPolicy->toArray()),
            'field_order'    => (string) json_encode($def->fieldOrder),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    /**
     * Overwrite all mutable columns for an existing row (matched by uuid).
     *
     * When $expectedSchemaVersion is given, the write only applies if the stored row still
     * carries that version (optimistic concurrency for schema mutations). Returns the number
     * of rows updated — 0 means the guard lost the race (or the row is gone).
     */
    public function update(CollectionDefinition $def, ?int $expectedSchemaVersion = null): int
    {
        $query = $this->connection->table('collection_definitions')
            ->where('uuid', $def->uuid);

        if ($expectedSchemaVersion !== null) {
            $query->where('schema_version', $expectedSchemaVersion);
        }

        return $query
            ->update([
                'name'           => $def->name,
                'label'          => $def->label,
                'table_name'     => $def->tableName,
                'storage_mode'   => $def->storageMode,
                'fields'         => $this->serializeFields($def),
                'schema_version' => $def->schemaVersion,
                'status'         => $def->status,
                'access_policy'  => (string) json_encode($def->accessPolicy->toArray()),
                'field_order'    => (string) json_encode($def->fieldOrder),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Remove a definition row by its UUID.
     *
     * Silently does nothing if no row with that UUID exists.
     */
    public function delete(string $uuid): void
    {
        $this->connection->table('collection_definitions')
            ->where('uuid', $uuid)
            ->delete();
    }

    /**
     * Look up a definition by its unique `name` field.
     *
     * Returns null when no collection with that name exists.
     */
    public function findByName(string $name): ?CollectionDefinition
    {
        $row = $this->connection->table('collection_definitions')
            ->where('name', $name)
            ->first();

        return $row !== null ? CollectionDefinition::fromRow($row) : null;
    }

    /**
     * Look up a definition by its public UUID.
     *
     * Returns null when no collection with that uuid exists.
     */
    public function findByUuid(string $uuid): ?CollectionDefinition
    {
        $row = $this->connection->table('collection_definitions')
            ->where('uuid', $uuid)
            ->first();

        return $row !== null ? CollectionDefinition::fromRow($row) : null;
    }

    /**
     * Return every persisted CollectionDefinition in insertion order.
     *
     * @return list<CollectionDefinition>
     */
    public function all(): array
    {
        $rows = $this->connection->table('collection_definitions')->get();

        return array_values(array_map(
            static fn (array $row): CollectionDefinition => CollectionDefinition::fromRow($row),
            $rows,
        ));
    }

    // ------------------------------------------------------------------

    private function serializeFields(CollectionDefinition $def): string
    {
        return (string) json_encode(
            array_map(static fn ($f) => $f->toArray(), $def->fields),
            JSON_THROW_ON_ERROR,
        );
    }
}
