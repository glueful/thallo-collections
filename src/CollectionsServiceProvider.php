<?php

declare(strict_types=1);

namespace Thallo\Collections;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Data\RowRepository;
use Thallo\Collections\Data\RowValidator;
use Thallo\Collections\Http\ActorResolver;
use Thallo\Collections\Http\CollectionAccessResolver;
use Thallo\Collections\Http\CollectionScopeMiddleware;
use Thallo\Collections\Http\Controllers\CollectionAdminSchemaController;
use Thallo\Collections\Http\Controllers\CollectionDataController;
use Thallo\Collections\Query\QueryCompiler;
use Thallo\Collections\Relations\RelationResolver;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\CollectionFieldTypes;
use Thallo\Collections\Schema\ColumnMapper;
use Thallo\Collections\Schema\DdlPlanner;
use Thallo\Collections\Schema\SchemaMaterializer;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Schema\FieldTypeRegistry;

final class CollectionsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ColumnMapper::class => [
                'class'    => ColumnMapper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            DdlPlanner::class => [
                'class'    => DdlPlanner::class,
                'shared'   => true,
                'autowire' => true,
            ],
            SchemaMaterializer::class => [
                'class'    => SchemaMaterializer::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionDefinitionRepository::class => [
                'class'    => CollectionDefinitionRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionManager::class => [
                'class'    => CollectionManager::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RowValidator::class => [
                'class'    => RowValidator::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RelationResolver::class => [
                'class'    => RelationResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RowRepository::class => [
                'class'    => RowRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            QueryCompiler::class => [
                'class'    => QueryCompiler::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ActorResolver::class => [
                'class'    => ActorResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionAccessResolver::class => [
                'class'    => CollectionAccessResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionScopeMiddleware::class => [
                'class'    => CollectionScopeMiddleware::class,
                'shared'   => true,
                'autowire' => true,
                'alias'    => ['collection_scope'],
            ],
            CollectionDataController::class => [
                'class'    => CollectionDataController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionAdminSchemaController::class => [
                'class'    => CollectionAdminSchemaController::class,
                'shared'   => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: migrations are loaded in boot() (the framework extension convention —
        // cf. aegis/users/import-export); DI bindings are declared via services().
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'thallo.collections',
            label: 'Data collections',
            description: 'Developer-defined data collections with a public CRUD/query API.',
        ));

        CollectionFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Migrations register on INSTALL, not enable (outside the gate below), so disabling
        // the capability still preserves the tables.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-collections',
        );

        // Routes are gated by ENABLED state (spec §5): register the public API only when the
        // capability is on. Disabling thallo.collections leaves migrations/tables intact but removes
        // the public surface entirely — requests 404 rather than reaching a disabled handler.
        if (app($context, CapabilityRegistry::class)->isEnabled('thallo.collections')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/collections.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
        }
    }
}
