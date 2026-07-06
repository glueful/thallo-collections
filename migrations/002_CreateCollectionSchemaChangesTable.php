<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCollectionSchemaChangesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('collection_schema_changes')) {
            return;
        }
        $schema->createTable('collection_schema_changes', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 24);
            $table->string('collection_uuid', 24);
            $table->string('change_type', 32);
            $table->text('payload');
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->boolean('destructive')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unique('uuid');
            $table->index('collection_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('collection_schema_changes');
    }

    public function getDescription(): string
    {
        return 'Create collection_schema_changes table (DDL audit log with recovery invariant).';
    }
}
