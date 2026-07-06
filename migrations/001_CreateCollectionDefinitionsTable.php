<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateCollectionDefinitionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('collection_definitions')) {
            return;
        }
        $schema->createTable('collection_definitions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 24);
            $table->string('name', 64);
            $table->string('label', 160);
            $table->string('table_name', 80);
            $table->string('storage_mode', 16)->default('table');
            $table->text('fields');
            $table->integer('schema_version')->default(1);
            $table->string('status', 16)->default('active');
            // Per-operation access policy {read,write,delete} of public|scoped (JSON). NULL rows
            // hydrate to the safe all-scoped default in CollectionDefinition::fromRow().
            $table->text('access_policy')->nullable();
            // Display order of all columns (system + custom) as a JSON list of names; NULL hydrates
            // to a system-first default. Controls how fields surface in the data browser / API.
            $table->text('field_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('uuid');
            $table->unique('name');
            $table->unique('table_name');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('collection_definitions');
    }

    public function getDescription(): string
    {
        return 'Create collection_definitions table (developer-defined data collections).';
    }
}
