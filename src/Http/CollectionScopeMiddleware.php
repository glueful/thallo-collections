<?php

declare(strict_types=1);

namespace Thallo\Collections\Http;

use Glueful\Http\Response;
use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-collection access gate for the public collections data API. Registered as 'collection_scope'.
 *
 * The collection's own access policy (per operation: read|write|delete) decides the requirement:
 *   - public  → allowed with no authentication.
 *   - scoped  → requires the `{collection}.{action}` capability (e.g. `products.write`).
 *
 * A scoped operation is satisfied by EXACTLY ONE of two mutually-exclusive credentials:
 *   - an api-key request (api_key_scopes set by optional_api_key) → its scopes are the ONLY
 *     authority; the key never inherits its owner's broader permissions; OR
 *   - otherwise, a session user — authenticated here, on demand, via the same AuthenticationManager
 *     the framework `auth` middleware uses (we can't attach `auth` statically because whether auth
 *     is required is per-collection runtime data) — authorized by their Aegis permission.
 *
 * Fail-closed: an unknown collection, a missing route name, or a scoped operation with neither a
 * satisfying scope nor permission all return 403. Method → operation: GET read, POST/PATCH/PUT
 * write, DELETE delete.
 */
final class CollectionScopeMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly CollectionDefinitionRepository $collections,
        private readonly CollectionAccessResolver $access,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $routeParams = (array) ($request->attributes->get('_route_params') ?? []);
        $name        = (string) ($routeParams['name'] ?? '');
        if ($name === '') {
            return Response::forbidden('Collection name could not be determined');
        }

        $operation  = $this->operation($request->getMethod());

        // Unknown collection → fail-closed (treat as scoped); the controller resolves the 404.
        $definition = $this->collections->findByName($name);

        if ($this->access->allows($request, $definition, $name, $operation)) {
            return $next($request);
        }

        // Mirror the prior, path-specific 403 messages.
        $capability = $name . '.' . $operation;
        $message = $request->attributes->has('api_key_scopes')
            ? 'Insufficient scope: ' . $capability
            : 'This operation requires the capability: ' . $capability;

        return Response::forbidden($message);
    }

    /** Map the HTTP method to the access-policy operation. */
    private function operation(string $method): string
    {
        return match (strtoupper($method)) {
            'DELETE' => 'delete',
            'POST', 'PATCH', 'PUT' => 'write',
            default => 'read',
        };
    }
}
