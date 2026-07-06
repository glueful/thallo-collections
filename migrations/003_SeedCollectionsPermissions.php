<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Declares the collections admin permissions — the permission ROWS the admin console gates on.
 *
 * The `collections.*` slugs exist only because this pack exists, so the pack declares them here.
 * Granting them to a role is host policy: the Thallo app grants them to `administrator` in its own
 * dependent migration (which runs after Aegis seeds the role ladder); a different host wires its own
 * grants. Keeping the grant out of this migration also avoids a cross-package migration-ordering
 * dependency (this would otherwise run before Aegis seeds the role).
 *
 * Rollback is additive-safe: `down()` is a NO-OP — removing the pack must not strip permission rows
 * that roles may still reference.
 */
final class SeedCollectionsPermissions implements MigrationInterface
{
    /** slug => label. Owned by thallo-collections. */
    private const PERMISSIONS = [
        'collections.manage' => 'Manage data collections',
        'collections.schema.manage' => 'Create, alter and drop collection structure and indexes',
        'collections.data.manage' => 'Create, edit and delete collection rows via the admin',
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        // The permissions table belongs to the RBAC provider (Aegis). On a host without
        // one there is nothing to seed into — skip rather than die mid-migration-run;
        // re-running migrations after installing the provider seeds the rows.
        if (!$schema->hasTable('permissions')) {
            return;
        }

        $this->db = new Connection();

        $existing = [];
        foreach (
            $this->db->table('permissions')->select(['slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $existing[$row['slug']] = true;
        }

        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (isset($existing[$slug])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(),
                'slug' => $slug,
                'name' => $label,
                'category' => 'collections',
                'description' => $label,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $this->db->table('permissions')->insertBatch($insert);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Intentional no-op (additive-safe): roles may still reference these permission rows.
    }

    public function getDescription(): string
    {
        return 'Declare the collections.* admin permissions (granted to roles by the host app).';
    }
}
