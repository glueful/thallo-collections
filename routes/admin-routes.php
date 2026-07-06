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
 *   3. lemma_permission — per-route Aegis permission: collections.manage to view, schema.manage to
 *                         mutate structure. Referenced by alias string, not a class import.
 */
$router->group(
    ['prefix' => '/v1/admin', 'middleware' => ['auth']],
    function (Router $router): void {
        // View.
        $router->get('/collections', [CollectionAdminSchemaController::class, 'index'])
            ->middleware('lemma_permission:collections.manage');
        $router->get('/collections/{name}', [CollectionAdminSchemaController::class, 'show'])
            ->middleware('lemma_permission:collections.manage');

        // Structure (schema) mutations.
        $router->post('/collections', [CollectionAdminSchemaController::class, 'store'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->post('/collections/{name}/fields', [CollectionAdminSchemaController::class, 'addField'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->delete('/collections/{name}/fields/{field}', [CollectionAdminSchemaController::class, 'dropField'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->post('/collections/{name}/indexes', [CollectionAdminSchemaController::class, 'addIndex'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->delete('/collections/{name}/indexes/{field}', [CollectionAdminSchemaController::class, 'dropIndex'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->patch('/collections/{name}/access', [CollectionAdminSchemaController::class, 'updateAccess'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->patch('/collections/{name}/field-order', [CollectionAdminSchemaController::class, 'updateFieldOrder'])
            ->middleware('lemma_permission:collections.schema.manage');
        $router->delete('/collections/{name}', [CollectionAdminSchemaController::class, 'destroy'])
            ->middleware('lemma_permission:collections.schema.manage');

        // Data browser (rows) — reuses the public CollectionDataController. The admin permission
        // gates it (god-mode over every collection, bypassing the per-collection scope/policy), and
        // the resolved actor is the admin session, so rows stamp created_by_type='admin'.
        $router->get('/collections/{name}/rows', [CollectionDataController::class, 'list'])
            ->middleware('lemma_permission:collections.data.manage');
        $router->get('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'show'])
            ->middleware('lemma_permission:collections.data.manage');
        $router->post('/collections/{name}/rows', [CollectionDataController::class, 'create'])
            ->middleware('lemma_permission:collections.data.manage');
        $router->patch('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'update'])
            ->middleware('lemma_permission:collections.data.manage');
        $router->delete('/collections/{name}/rows/{uuid}', [CollectionDataController::class, 'delete'])
            ->middleware('lemma_permission:collections.data.manage');
        $router->delete('/collections/{name}/rows', [CollectionDataController::class, 'truncate'])
            ->middleware('lemma_permission:collections.data.manage');
    },
);
