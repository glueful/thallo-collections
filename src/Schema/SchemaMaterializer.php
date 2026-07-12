<?php

declare(strict_types=1);

namespace Thallo\Collections\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\ColumnBuilderInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Interfaces\TableBuilderInterface;
use Thallo\Collections\Exceptions\PreflightFailedException;
use Thallo\Collections\Support\PublicId;

/**
 * Executes a list of SchemaChange ops as real DDL, bracketing each with a two-phase audit row.
 *
 * ## Two-phase audit invariant
 *
 * For EVERY op, in strict order:
 *   1. Pre-flight — if adding a unique index, SELECT GROUP BY HAVING COUNT>1 and throw
 *      PreflightFailedException *before* writing anything if duplicates exist.
 *   2. Write `collection_schema_changes` row with `status='pending'` BEFORE the DDL.
 *   3. Execute the DDL via SchemaBuilderInterface.
 *   4a. On success → UPDATE the row to `status='applied', applied_at=now`.
 *   4b. On exception → try to UPDATE to `status='failed'`, then rethrow.
 *
 * ## Transaction policy
 *
 * - PostgreSQL / SQLite: DDL is transactional. The entire op list runs inside a single
 *   `Connection::transaction()` wrapper. If any op fails, the DB rolls back — audit rows
 *   included. The pending→failed update in the catch block may fail (tx aborted) and is
 *   silently swallowed.
 *
 * - MySQL: DDL auto-commits and cannot be rolled back. No wrapper transaction is opened.
 *   The pending→applied/failed audit trail IS the recovery record: a lingering `pending`
 *   or `failed` row with no corresponding `applied` row signals a change that needs
 *   re-application or manual reconciliation.
 *
 * ## System columns added to every create_table op
 *
 *   id             BIGINT PRIMARY KEY AUTO_INCREMENT
 *   uuid           VARCHAR(64) UNIQUE
 *   created_at     TIMESTAMP NULL
 *   updated_at     TIMESTAMP NULL
 *   created_by_type VARCHAR(64) NULL
 *   created_by_id   VARCHAR(64) NULL
 *   updated_by_type VARCHAR(64) NULL
 *   updated_by_id   VARCHAR(64) NULL
 */
final class SchemaMaterializer
{
    public function __construct(
        private readonly SchemaBuilderInterface $schema,
        private readonly Connection $connection,
        private readonly ColumnMapper $mapper,
    ) {
    }

    /**
     * Apply a planned list of SchemaChange ops to the database.
     *
     * @param list<SchemaChange> $ops
     *
     * @throws PreflightFailedException if a unique-index op would violate uniqueness.
     * @throws \Throwable               if any DDL op fails (exception is re-thrown after audit).
     */
    public function apply(
        CollectionDefinition $def,
        array $ops,
        string $actorType,
        ?string $actorId,
    ): void {
        $driver = $this->connection->getDriverName();
        $useTransaction = in_array($driver, ['pgsql', 'sqlite'], true);

        $execute = function () use ($def, $ops, $actorType, $actorId): void {
            foreach ($ops as $op) {
                $this->executeOp($def, $op, $actorType, $actorId);
            }
        };

        if ($useTransaction) {
            $this->connection->transaction($execute);
        } else {
            $execute();
        }
    }

    // ----------------------------------------------------------------- private

    private function executeOp(
        CollectionDefinition $def,
        SchemaChange $op,
        string $actorType,
        ?string $actorId,
    ): void {
        // Phase 1: pre-flight — must run BEFORE any write.
        $this->preflight($def, $op);

        // Phase 2: write the pending audit row BEFORE the DDL.
        $auditUuid = PublicId::generate('sc');
        $now       = date('Y-m-d H:i:s');

        $this->connection->table('collection_schema_changes')->insert([
            'uuid'            => $auditUuid,
            'tenant_uuid'     => $def->tenantUuid,
            'collection_uuid' => $def->uuid,
            'change_type'     => $op->op,
            'payload'         => (string) json_encode(
                $op->field !== null ? $op->field->toArray() : [],
                JSON_THROW_ON_ERROR,
            ),
            'actor_type'      => $actorType,
            'actor_id'        => $actorId,
            'destructive'     => $op->destructive ? 1 : 0,
            'status'          => 'pending',
            'created_at'      => $now,
            'applied_at'      => null,
        ]);

        // Phase 3: execute the DDL.
        try {
            $this->executeDdl($def, $op);
        } catch (\Throwable $e) {
            // On failure: mark as failed.
            // Inside a pgsql transaction the UPDATE may itself fail (tx already aborted);
            // suppress that — the outer transaction will roll back the pending row anyway.
            try {
                $this->connection->table('collection_schema_changes')
                    ->where('uuid', $auditUuid)
                    ->where('tenant_uuid', $def->tenantUuid)
                    ->update(['status' => 'failed']);
            } catch (\Throwable) {
                // Intentionally suppressed (see class docblock for reasoning).
            }

            throw $e;
        }

        // On success: mark as applied.
        $this->connection->table('collection_schema_changes')
            ->where('uuid', $auditUuid)
            ->where('tenant_uuid', $def->tenantUuid)
            ->update(['status' => 'applied', 'applied_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Pre-flight: for a planned unique `add_index`, abort if the column already has
     * duplicate values (applying the constraint would fail at the DB level).
     *
     * @throws PreflightFailedException
     */
    private function preflight(CollectionDefinition $def, SchemaChange $op): void
    {
        if ($op->op !== 'add_index' || $op->field === null || $op->indexKind !== 'unique') {
            return;
        }

        $spec = $this->mapper->column($op->field);

        // NULLs are excluded: the database allows any number of NULLs under a unique
        // index (PostgreSQL default NULLS DISTINCT), so they are not duplicates.
        $dupes = $this->connection
            ->table($def->tableName)
            ->select([$spec->name])
            ->whereNotNull($spec->name)
            ->groupBy($spec->name)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($dupes !== []) {
            throw PreflightFailedException::duplicateValues($spec->name, $def->tableName);
        }
    }

    /**
     * Dispatch to the correct SchemaBuilder call for each op type.
     */
    private function executeDdl(CollectionDefinition $def, SchemaChange $op): void
    {
        switch ($op->op) {
            case 'create_table':
                $this->schema->createTable(
                    $def->tableName,
                    function (TableBuilderInterface $t) use ($def): void {
                        $this->addSystemColumns($t);
                        foreach ($def->fields as $field) {
                            $this->addFieldColumn($t, $field);
                        }
                    },
                );
                break;

            case 'add_field':
                $field = $op->field
                    ??
                throw new \LogicException("add_field op is missing its field");
                $spec  = $this->mapper->column($field);

                $this->schema->alterTable(
                    $def->tableName,
                    function (TableBuilderInterface $t) use ($spec): void {
                        $this->applyColumnSpec($t, $spec);
                    },
                );
                break;

            case 'drop_field':
                $field     = $op->field
                    ??
                throw new \LogicException("drop_field op is missing its field");
                $fieldName = $field->name;

                $this->schema->alterTable(
                    $def->tableName,
                    static function (TableBuilderInterface $t) use ($fieldName): void {
                        $t->dropColumn($fieldName);
                    },
                );
                break;

            case 'add_index':
                $field     = $op->field
                    ??
                throw new \LogicException("add_index op is missing its field");
                $spec      = $this->mapper->column($field);
                $colName   = $spec->name;
                // The kind was fixed at plan time — never re-derive it from the field's
                // (post-change) settings, which describe the target state, not the op.
                $isUnique  = $op->indexKind === 'unique';

            if ($isUnique) {
                $indexName = CollectionPhysicalName::indexName($def->tableName, $colName, 'unique');
                $this->schema->alterTable(
                    $def->tableName,
                    static function (TableBuilderInterface $t) use ($colName, $indexName): void {
                        $t->unique($colName, $indexName);
                    },
                );
            } else {
                $indexName = CollectionPhysicalName::indexName($def->tableName, $colName, 'plain');
                $this->schema->alterTable(
                    $def->tableName,
                    static function (TableBuilderInterface $t) use ($colName, $indexName): void {
                        $t->index($colName, $indexName);
                    },
                );
            }
                break;

            case 'drop_index':
                $field    = $op->field
                    ??
                throw new \LogicException("drop_index op is missing its field");
                $spec     = $this->mapper->column($field);
                $colName  = $spec->name;
                $isUnique = $op->indexKind === 'unique';
                $table    = $def->tableName;

            if ($isUnique) {
                $idxName = CollectionPhysicalName::indexName($table, $colName, 'unique');
                $this->schema->alterTable(
                    $table,
                    static function (TableBuilderInterface $t) use ($idxName): void {
                        $t->dropUnique($idxName);
                    },
                );
            } else {
                $idxName = CollectionPhysicalName::indexName($table, $colName, 'plain');
                $this->schema->alterTable(
                    $table,
                    static function (TableBuilderInterface $t) use ($idxName): void {
                        $t->dropIndex($idxName);
                    },
                );
            }
                break;

            case 'drop_table':
                $this->schema->dropTable($def->tableName);
                $this->schema->execute();
                break;

            default:
                throw new \InvalidArgumentException(
                    sprintf("Unknown SchemaChange op '%s'", $op->op),
                );
        }
    }

    /**
     * Add the eight system columns that every collection table carries.
     *
     * Column list (v1):
     *   id              BIGINT      PK AUTO_INCREMENT
     *   uuid            VARCHAR(64) UNIQUE NOT NULL
     *   created_at      TIMESTAMP   NULL
     *   updated_at      TIMESTAMP   NULL
     *   created_by_type VARCHAR(64) NULL
     *   created_by_id   VARCHAR(64) NULL
     *   updated_by_type VARCHAR(64) NULL
     *   updated_by_id   VARCHAR(64) NULL
     */
    private function addSystemColumns(TableBuilderInterface $t): void
    {
        $t->bigInteger('id')->primary()->autoIncrement();
        $t->string('uuid', 64)->unique();
        $t->timestamp('created_at')->nullable();
        $t->timestamp('updated_at')->nullable();
        $t->string('created_by_type', 64)->nullable();
        $t->string('created_by_id', 64)->nullable();
        $t->string('updated_by_type', 64)->nullable();
        $t->string('updated_by_id', 64)->nullable();
    }

    /**
     * Map a CollectionField to its ColumnSpec and add it to the table builder.
     */
    private function addFieldColumn(TableBuilderInterface $t, CollectionField $field): void
    {
        $this->applyColumnSpec($t, $this->mapper->column($field));
    }

    /**
     * Add a ColumnSpec's column to the builder. Only `nullable` is applied inline —
     * indexes (unique and plain) arrive as separate planned add_index ops. Inline
     * `->unique()` at CREATE TABLE becomes a Postgres table constraint that the
     * drop_index path (DROP INDEX) cannot remove, and inline `->index()` is silently
     * discarded by the create-table SQL generator; the alter path produces real,
     * droppable index artifacts for both.
     */
    private function applyColumnSpec(TableBuilderInterface $t, ColumnSpec $spec): void
    {
        $col = $this->buildColumn($t, $spec);

        if ($spec->nullable) {
            $col->nullable();
        }
    }

    /**
     * Call the appropriate TableBuilder method for the given ColumnSpec type.
     *
     * @throws \InvalidArgumentException if the ColumnSpec carries an unrecognised type.
     */
    private function buildColumn(TableBuilderInterface $t, ColumnSpec $spec): ColumnBuilderInterface
    {
        return match ($spec->type) {
            'string'     => $t->string(
                $spec->name,
                isset($spec->params[0]) ? (int) $spec->params[0] : 255,
            ),
            'text'       => $t->text($spec->name),
            'integer'    => $t->integer($spec->name),
            'bigInteger' => $t->bigInteger($spec->name),
            'boolean'    => $t->boolean($spec->name),
            'decimal'    => $t->decimal(
                $spec->name,
                isset($spec->params[0]) ? (int) $spec->params[0] : 8,
                isset($spec->params[1]) ? (int) $spec->params[1] : 2,
            ),
            'timestamp'  => $t->timestamp($spec->name),
            'date'       => $t->date($spec->name),
            default      => throw new \InvalidArgumentException(
                sprintf(
                    "ColumnSpec type '%s' for field '%s' has no TableBuilder mapping.",
                    $spec->type,
                    $spec->name,
                ),
            ),
        };
    }
}
