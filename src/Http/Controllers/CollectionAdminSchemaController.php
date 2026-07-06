<?php

declare(strict_types=1);

namespace Thallo\Collections\Http\Controllers;

use Glueful\Http\Response;
use Thallo\Collections\CollectionManager;
use Thallo\Collections\Exceptions\BlockedSchemaChangeException;
use Thallo\Collections\Exceptions\CollectionValidationException;
use Thallo\Collections\Exceptions\ConcurrentSchemaChangeException;
use Thallo\Collections\Exceptions\DestructiveConfirmationRequiredException;
use Thallo\Collections\Exceptions\PreflightFailedException;
use Thallo\Collections\Http\ActorResolver;
use Thallo\Collections\Http\DTOs\AddIndexData;
use Thallo\Collections\Http\DTOs\CreateCollectionData;
use Thallo\Collections\Http\DTOs\FieldData;
use Thallo\Collections\Http\DTOs\UpdateAccessData;
use Thallo\Collections\Http\DTOs\UpdateFieldOrderData;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\AccessPolicy;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin schema-management API for collections (models): list/show, create, add/drop fields, add/drop
 * indexes, replace the access policy, and drop a collection.
 *
 * A thin HTTP layer over {@see CollectionManager}; the actor is resolved from the request and stamped
 * on every structural change. Domain failures map to HTTP: CollectionValidationException,
 * BlockedSchemaChangeException and PreflightFailedException → 422;
 * DestructiveConfirmationRequiredException and ConcurrentSchemaChangeException → 409; an unknown
 * collection or field (\DomainException from the manager) → 404.
 */
final class CollectionAdminSchemaController
{
    public function __construct(
        private readonly CollectionManager $manager,
        private readonly CollectionDefinitionRepository $collections,
        private readonly ActorResolver $actors,
    ) {
    }

    #[ApiOperation(summary: 'List collections', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'All collections with their schema.')]
    public function index(Request $request): Response
    {
        return Response::success(['collections' => $this->collections->all()], 'Collections retrieved.');
    }

    #[ApiOperation(summary: 'Get a collection', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'The collection and its schema.')]
    public function show(Request $request, string $name): Response
    {
        $definition = $this->collections->findByName($name);

        return $definition === null
            ? Response::notFound("Collection '{$name}' not found.")
            : Response::success(['collection' => $definition], 'Collection retrieved.');
    }

    #[ApiOperation(summary: 'Create a collection', tags: ['Collections Admin'])]
    #[ApiResponse(201, description: 'Collection created.')]
    public function store(CreateCollectionData $data, Request $request): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->create($data->toPayload(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::created(['collection' => $definition], "Collection '{$definition->name}' created.");
    }

    #[ApiOperation(summary: 'Add a field', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Field added.')]
    public function addField(FieldData $data, Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->addField($name, $data->toArray(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Field added.');
    }

    #[ApiOperation(summary: 'Drop a field (guarded)', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Field dropped.')]
    public function dropField(Request $request, string $name, string $field): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->dropField(
                $name,
                $field,
                ['confirm' => $this->confirm($request)],
                $actor->type,
                $actor->id,
            );
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Field dropped.');
    }

    #[ApiOperation(summary: 'Add an index', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Index added.')]
    public function addIndex(AddIndexData $data, Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->addIndex($name, $data->field, $data->toSettings(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Index added.');
    }

    #[ApiOperation(summary: 'Remove an index', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Index removed.')]
    public function dropIndex(Request $request, string $name, string $field): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->removeIndex($name, $field, $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Index removed.');
    }

    #[ApiOperation(summary: 'Replace the access policy', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Access policy updated.')]
    public function updateAccess(UpdateAccessData $data, Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->setAccessPolicy(
                $name,
                AccessPolicy::fromArray($data->toArray()),
                $actor->type,
                $actor->id,
            );
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Access policy updated.');
    }

    #[ApiOperation(summary: 'Reorder a collection’s fields', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Field order updated.')]
    public function updateFieldOrder(UpdateFieldOrderData $data, string $name): Response
    {
        try {
            $definition = $this->manager->setFieldOrder($name, $data->field_order);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Field order updated.');
    }

    #[ApiOperation(summary: 'Drop a collection (guarded)', tags: ['Collections Admin'])]
    #[ApiResponse(200, description: 'Collection deleted.')]
    public function destroy(Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $this->manager->dropCollection($name, ['confirm' => $this->confirm($request)], $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success([], "Collection '{$name}' deleted.");
    }

    /** The optional `confirm` body field forwarded to guarded-drop operations. */
    private function confirm(Request $request): ?string
    {
        $content = $request->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        $confirm = is_array($decoded) ? ($decoded['confirm'] ?? null) : null;

        return is_string($confirm) ? $confirm : null;
    }

    private function mapException(\Throwable $e): Response
    {
        return match (true) {
            $e instanceof DestructiveConfirmationRequiredException => Response::error($e->getMessage(), 409),
            $e instanceof ConcurrentSchemaChangeException => Response::error($e->getMessage(), 409),
            $e instanceof CollectionValidationException => Response::validation($e->errors()),
            $e instanceof BlockedSchemaChangeException => Response::validation(['schema' => $e->getMessage()]),
            $e instanceof PreflightFailedException => Response::validation(['index' => $e->getMessage()]),
            $e instanceof \DomainException => Response::notFound($e->getMessage()),
            default => throw $e,
        };
    }
}
