<?php

declare(strict_types=1);

namespace Thallo\Collections\Data;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Events\CollectionTruncated;
use Thallo\Collections\Exceptions\RowNotFoundException;
use Thallo\Collections\Relations\RelationResolver;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Support\PublicId;

/**
 * CRUD gateway for rows stored in a collection's materialized table.
 *
 * All writes go through RowValidator before touching the database.
 * System columns (uuid, created_at, updated_at, created_by_*, updated_by_*)
 * are managed here and never exposed to the caller's $input.
 *
 * Relation fields whose target descriptor is `collection:*` are validated for target
 * existence on every write (assertTargetsExist). Deleting a row that is referenced by
 * another collection's relation field is blocked (assertNotReferenced — restrict-delete).
 *
 * Each successful write dispatches the matching CollectionRow* event via EventService so
 * downstream consumers (realtime, webhooks, search) can react without coupling to this class.
 */
final class RowRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RowValidator $validator,
        private readonly RelationResolver $resolver,
        private readonly EventService $events,
    ) {
    }

    /**
     * Insert a new row into the collection's table.
     *
     * Validates $input (full, not partial), asserts all relation targets exist,
     * generates a uuid, stamps actor + timestamps, inserts the row, dispatches
     * CollectionRowCreated, and returns the full stored row.
     *
     * @param array<string, mixed> $input  Field values supplied by the caller.
     * @return array<string, mixed>        The inserted row as read back from the database.
     * @throws \Thallo\Collections\Exceptions\RowValidationException on validation failure.
     */
    public function create(CollectionDefinition $def, array $input, Actor $actor): array
    {
        $coerced = $this->validator->validate($def, $input, false);

        $now  = date('Y-m-d H:i:s');
        $uuid = PublicId::generate('row');

        $row = array_merge($coerced, [
            'uuid'            => $uuid,
            'created_at'      => $now,
            'updated_at'      => $now,
            'created_by_type' => $actor->type,
            'created_by_id'   => $actor->id,
            'updated_by_type' => $actor->type,
            'updated_by_id'   => $actor->id,
        ]);

        // Target-existence check + insert share one transaction for atomic visibility.
        // This narrows (not closes — plain MVCC reads don't block) the race with a
        // concurrent delete of a referenced target; closing it fully needs FOR SHARE /
        // FOR UPDATE row locks, which the framework query builder does not expose yet.
        $stored = $this->withinTransaction(function () use ($def, $input, $row, $uuid): array {
            $this->assertRelationTargets($def, $input);
            $this->connection->table($def->tableName)->insert($row);

            return $this->fetchOrFail($def, $uuid);
        });

        // After commit — listeners must never observe a row that can still roll back.
        $this->connection->afterCommit(fn () => $this->events->dispatch(
            new CollectionRowCreated($def->name, $uuid, $stored, $actor, $def->tenantUuid),
        ));

        return $stored;
    }

    /**
     * Partially update an existing row.
     *
     * Only the fields present in $input are modified; absent fields retain their stored values.
     * Asserts relation targets exist for any relation fields present in $input.
     * Stamps updated_by_* and updated_at; never changes created_by_* columns.
     * Dispatches CollectionRowUpdated after a successful write.
     *
     * @param array<string, mixed> $input  Partial field values to apply.
     * @return array<string, mixed>        The updated row as read back from the database.
     * @throws RowNotFoundException        when no row with $uuid exists.
     * @throws \Thallo\Collections\Exceptions\RowValidationException on validation failure.
     */
    public function update(CollectionDefinition $def, string $uuid, array $input, Actor $actor): array
    {
        // Confirm the row exists before validating.
        $this->fetchOrFail($def, $uuid);

        $coerced = $this->validator->validate($def, $input, true, $uuid);

        $changes = array_merge($coerced, [
            'updated_at'      => date('Y-m-d H:i:s'),
            'updated_by_type' => $actor->type,
            'updated_by_id'   => $actor->id,
        ]);

        // Same transaction bracket as create() (see the comment there).
        $stored = $this->withinTransaction(function () use ($def, $input, $uuid, $changes): array {
            $this->assertRelationTargets($def, $input);
            $this->connection->table($def->tableName)
                ->where('uuid', $uuid)
                ->update($changes);

            return $this->fetchOrFail($def, $uuid);
        });

        $this->connection->afterCommit(fn () => $this->events->dispatch(
            new CollectionRowUpdated($def->name, $uuid, $stored, $actor, $def->tenantUuid),
        ));

        return $stored;
    }

    /**
     * Delete a row from the collection's table.
     *
     * Asserts that no other collection references $uuid (restrict-delete). If the row is
     * referenced, throws RowReferencedException without touching the database.
     * Dispatches CollectionRowDeleted after a successful delete.
     *
     * @throws RowNotFoundException        when no row with $uuid exists.
     * @throws \Thallo\Collections\Exceptions\RowReferencedException when referenced.
     */
    public function delete(CollectionDefinition $def, string $uuid, Actor $actor): void
    {
        $this->fetchOrFail($def, $uuid);

        // Reference check + delete share one transaction (see create() for the residual
        // race caveat — a referencing row committed between the check and the delete).
        $this->withinTransaction(function () use ($def, $uuid): void {
            $this->resolver->assertNotReferenced($def, $uuid);
            $this->connection->table($def->tableName)
                ->where('uuid', $uuid)
                ->delete();
        });

        $this->connection->afterCommit(fn () => $this->events->dispatch(
            new CollectionRowDeleted($def->name, $uuid, $actor, $def->tenantUuid),
        ));
    }

    /**
     * Empty the collection's table — a real TRUNCATE that also resets the auto-increment id —
     * keeping the schema. A deliberate bulk admin action: no per-row events or reference
     * checks; a single CollectionTruncated event carries the actor and count for audit.
     *
     * Interpolating the table name is safe ONLY because it is 'coll_' + the collection
     * name, which CollectionManager validates against /^[a-z][a-z0-9_]*$/ at create time —
     * that gate is load-bearing for this method. No CASCADE: collection relations are
     * loose uuids by design, and if a real FK ever appeared, CASCADE would silently
     * truncate the referencing table too.
     */
    public function truncate(CollectionDefinition $def, Actor $actor): int
    {
        $table = $def->tableName;
        $count = (int) $this->connection->table($table)->count();

        // PostgreSQL: RESTART IDENTITY resets the auto-increment id.
        $this->connection->getPDO()->exec("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY");

        $this->connection->afterCommit(fn () => $this->events->dispatch(
            new CollectionTruncated($def->name, $count, $actor, $def->tenantUuid),
        ));

        return $count;
    }

    /**
     * Retrieve a row from the collection's table by UUID.
     *
     * @return array<string, mixed>
     * @throws RowNotFoundException when no row with $uuid exists.
     */
    public function find(CollectionDefinition $def, string $uuid): array
    {
        return $this->fetchOrFail($def, $uuid);
    }

    // ------------------------------------------------------------------

    /**
     * For each relation field present in $input, assert that every referenced uuid exists
     * in the target collection's table.
     *
     * Called after shape-validation (RowValidator) so the values are already confirmed to be
     * the correct shape (string / array of strings). The original $input (not the coerced
     * column map) is used so multi-relation values are still PHP arrays rather than JSON strings.
     *
     * @param array<string, mixed> $input
     */
    private function assertRelationTargets(CollectionDefinition $def, array $input): void
    {
        foreach ($def->fields as $field) {
            if ($field->type !== 'collections.relation') {
                continue;
            }

            if (!array_key_exists($field->name, $input)) {
                continue;
            }

            $value = $input[$field->name];

            if ($value === null) {
                continue; // nullable relation with no value — already allowed by validator
            }

            $this->resolver->assertTargetsExist($field, $value);
        }
    }

    /**
     * Fetch a row by UUID, throwing RowNotFoundException when absent.
     *
     * @return array<string, mixed>
     * @throws RowNotFoundException
     */
    private function fetchOrFail(CollectionDefinition $def, string $uuid): array
    {
        $row = $this->connection->table($def->tableName)
            ->where('uuid', $uuid)
            ->first();

        if ($row === null) {
            throw RowNotFoundException::forUuid($def->tableName, $uuid);
        }

        return $row;
    }

    private function withinTransaction(callable $callback): mixed
    {
        if ($this->connection->getPDO()->inTransaction()) {
            return $callback();
        }

        return $this->connection->transaction($callback);
    }
}
