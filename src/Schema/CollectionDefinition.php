<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

/**
 * Readonly value object representing a collection's full definition.
 *
 * storageMode is always 'table' in v1; fromRow() defaults a missing storage_mode to 'table'.
 */
final class CollectionDefinition
{
    /**
     * @param list<CollectionField> $fields
     * @param list<string> $fieldOrder display order of all column names (system + custom)
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $label,
        public readonly string $tableName,
        public readonly string $storageMode,
        public readonly array $fields,
        public readonly int $schemaVersion,
        public readonly string $status,
        public readonly AccessPolicy $accessPolicy = new AccessPolicy(
            AccessPolicy::SCOPED,
            AccessPolicy::SCOPED,
            AccessPolicy::SCOPED,
        ),
        public readonly array $fieldOrder = [],
        public readonly string $tenantUuid = '',
    ) {
    }

    /**
     * Hydrate from a collection_definitions database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $name = (string) ($row['name'] ?? '?');

        // Corrupt persisted schema must fail loudly. Degrading to "zero fields" would make
        // a later alter plan add_field for every column (duplicate-column DDL errors) and
        // hide the real problem behind downstream noise.
        $rawFields = $row['fields'] ?? '[]';
        if (is_string($rawFields)) {
            try {
                $rawFields = json_decode($rawFields, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    sprintf("Corrupt fields JSON on collection '%s': %s", $name, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }

        $fields = [];
        foreach ((array) $rawFields as $f) {
            if (!is_array($f)) {
                throw new \RuntimeException(sprintf(
                    "Corrupt fields entry on collection '%s': expected object, got %s.",
                    $name,
                    get_debug_type($f),
                ));
            }
            $fields[] = CollectionField::fromArray($f);
        }

        $rawPolicy = $row['access_policy'] ?? null;
        if (is_string($rawPolicy) && $rawPolicy !== '') {
            $rawPolicy = json_decode($rawPolicy, true);
        }
        $accessPolicy = is_array($rawPolicy) ? AccessPolicy::fromArray($rawPolicy) : AccessPolicy::default();

        $rawOrder = $row['field_order'] ?? null;
        if (is_string($rawOrder) && $rawOrder !== '') {
            $rawOrder = json_decode($rawOrder, true);
        }
        $fieldOrder = is_array($rawOrder) ? array_values(array_filter($rawOrder, 'is_string')) : [];

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            tenantUuid: (string) ($row['tenant_uuid'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            tableName: (string) ($row['table_name'] ?? ''),
            storageMode: (string) ($row['storage_mode'] ?? 'table'),
            fields: $fields,
            schemaVersion: (int) ($row['schema_version'] ?? 1),
            status: (string) ($row['status'] ?? 'draft'),
            accessPolicy: $accessPolicy,
            fieldOrder: $fieldOrder,
        );
    }

    /**
     * Look up a field by name; returns null when no field with that name exists.
     */
    public function field(string $name): ?CollectionField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
