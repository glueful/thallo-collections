<?php

declare(strict_types=1);

namespace Thallo\Collections\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Thallo\Collections\Schema\CollectionPhysicalName;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;
use Thallo\Tenancy\Purge\PurgeHandler;

final class CollectionsPurgeHandler implements PurgeHandler
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantApiKeyBindingRepository $bindings,
    ) {
    }

    public function id(): string
    {
        return 'thallo.collections';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        $rows = $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)
            ->orderBy('uuid', 'asc')
            ->get();

        return [
            'tenant_uuid' => $tenantUuid,
            'collections' => array_values(array_map(static fn (array $row): array => [
                'definition_uuid' => (string) $row['uuid'],
                'table_name' => (string) $row['table_name'],
            ], $rows)),
            'api_key_uuids' => $this->bindings->bindingsForTenant($tenantUuid),
        ];
    }

    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        foreach ($this->validatedTables($tenantUuid, $artifacts) as $tuple) {
            if (!$this->tableExists($tuple['table_name'])) {
                continue;
            }
            $live = $this->connection->table('collection_definitions')
                ->where('tenant_uuid', $tenantUuid)
                ->where('uuid', $tuple['definition_uuid'])
                ->where('table_name', $tuple['table_name'])
                ->first();
            if ($live === null) {
                throw new \RuntimeException('Collection purge artifact no longer matches live ownership metadata.');
            }
            $this->connection->getPDO()->exec('DROP TABLE IF EXISTS "' . $tuple['table_name'] . '"');
        }

        $this->connection->table('collection_schema_changes')
            ->where('tenant_uuid', $tenantUuid)
            ->forceDelete();
        $this->connection->table('collection_definitions')
            ->where('tenant_uuid', $tenantUuid)
            ->forceDelete();
        foreach ($this->bindings->bindingsForTenant($tenantUuid) as $apiKeyUuid) {
            $this->bindings->unbind($apiKeyUuid);
        }
    }

    public function verify(ApplicationContext $context, string $tenantUuid, array $artifacts): bool
    {
        foreach ($this->validatedTables($tenantUuid, $artifacts) as $tuple) {
            if ($this->tableExists($tuple['table_name'])) {
                return false;
            }
        }
        foreach (['collection_schema_changes', 'collection_definitions', 'thallo_tenant_api_key_bindings'] as $table) {
            $statement = $this->connection->getPDO()->prepare(
                "SELECT 1 FROM {$table} WHERE tenant_uuid = ? LIMIT 1",
            );
            $statement->execute([$tenantUuid]);
            if ($statement->fetchColumn() !== false) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{definition_uuid:string,table_name:string}> */
    private function validatedTables(string $tenantUuid, array $artifacts): array
    {
        if (($artifacts['tenant_uuid'] ?? null) !== $tenantUuid || !is_array($artifacts['collections'] ?? null)) {
            throw new \RuntimeException('Invalid collection purge manifest.');
        }
        $validated = [];
        $seenDefinitions = [];
        $seenTables = [];
        foreach ($artifacts['collections'] as $tuple) {
            $definitionUuid = is_array($tuple) ? ($tuple['definition_uuid'] ?? null) : null;
            $tableName = is_array($tuple) ? ($tuple['table_name'] ?? null) : null;
            if (
                !is_string($definitionUuid) || $definitionUuid === '' || !is_string($tableName)
                || !CollectionPhysicalName::belongsToTenant($tableName, $tenantUuid)
                || isset($seenDefinitions[$definitionUuid]) || isset($seenTables[$tableName])
            ) {
                throw new \RuntimeException('Unsafe collection in purge manifest.');
            }
            $seenDefinitions[$definitionUuid] = true;
            $seenTables[$tableName] = true;
            $validated[] = ['definition_uuid' => $definitionUuid, 'table_name' => $tableName];
        }
        return $validated;
    }

    private function tableExists(string $tableName): bool
    {
        $statement = $this->connection->getPDO()->prepare('SELECT to_regclass(?)');
        $statement->execute([$tableName]);
        return $statement->fetchColumn() !== null;
    }
}
