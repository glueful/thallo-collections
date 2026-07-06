<?php

declare(strict_types=1);

use Thallo\Collections\Http\Controllers\CollectionAdminSchemaController;
use Thallo\Collections\Http\Controllers\CollectionDataController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin schema-management API. Triple-gated:
 *   1. capability       — this file is only loaded when lemma.collections is enabled (the boot
 *                         gate skips it otherwise, so requests 404 rather than reaching a handler).
 *   2. auth             — group middleware: an authenticated session is required (401 otherwise).
 *   3. content_permission — per-route Aegis permission: collections.manage to view, schema.manage to
 *                         mutate structure. Referenced by alias string, not a class import.
 */
$router->group(
    ['prefix' => '/v1/admin', 'middleware' => ['auth']],
    function (Router $router): void {
        // View.
        $router->get('/collections', [CollectionAdminSchemaController::class, 'index'])
            ->middleware('content_permission:collections.manage');
        $router->get('/collections/{name}', [CollectionAdminSchemaController::class, 'show'])
            ->middleware('content_permission:collections.manage');

        // Structure (schema) mutations.
        $router->post('/collections', [CollectionAdminSchemaController::class, 'store'])
            ->middleware('content_permission:collections.schema.manage');
        $router->post('/collections/{name}/fields', [CollectionAdminSchemaController::class, 'addField'])
            ->middleware('content_permission:collections.schema.manage');
        $router->delete('/collections/{name}/fields/{field}', [CollectionAdminSchemaController::class, 'dropField'])
            ->middleware('content_permission:collections.schema.manage');
        $router->post('/collections/{name}/indexes', [CollectionAdminSchemaController::class, 'addIndex'])
            ->middleware('content_permission:collections.schema.manage');
        $router->delete('/collections/{name}/indexes/{field}', [CollectionAdminSchemaController::class, 'dropIndex'])
            ->middleware('content_permission:collections.schema.manage');
        $router->patch('/collections/{name}/access', [CollectionAdminSchemaController::class, 'updateAccess'])
            ->middleware('content_permission:collections.schema.manage');
        $router->patch('/collections/{name}/field-order', [CollectionAdminSchemaController::class, 'updateFieldOrder'])
            ->middleware('content_permission:collections.schema.manage');
        $router->delete('/collections/{name}', [CollectionAdminSchemaController::class, 'destroy'])
            ->middleware('content_permission:collections.schema.manage');

        // Data browser (rows) — reuses the public CollectionDataController. The admin permission
        // gates it (god-mode over every collection, bypassing the per-collection scope/policy), and
        // the resolved actor is the admin session, so rows stamp created_by_type='admin'.
        $router->get('/collections/{name}/rows', [CollectionDataController::class, 'list'])
            ->middleware('content_permission:collections.data.manage');
        $router->get('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'show'])
            ->middleware('content_permission:collections.data.manage');
        $router->post('/collections/{name}/rows', [CollectionDataController::class, 'create'])
            ->middleware('content_permission:collections.data.manage');
        $router->patch('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'update'])
            ->middleware('content_permission:collections.data.manage');
        $router->delete('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'delete'])
            ->middleware('content_permission:collections.data.manage');
        $router->delete('/collections/{name}/rows', [CollectionDataController::class, 'truncate'])
            ->middleware('content_permission:collections.data.manage');
    },
);
