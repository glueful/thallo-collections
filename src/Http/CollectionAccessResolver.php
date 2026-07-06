<?php

declare(strict_types=1);

namespace Thallo\Collections\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\AuthenticationManager;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Collections\Schema\AccessPolicy;
use Thallo\Collections\Schema\CollectionDefinition;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The per-collection, per-operation access decision — the single source of truth shared by
 * {@see CollectionScopeMiddleware} (which gates the URL collection) and
 * {@see \Thallo\Collections\Relations\RelationResolver} (which must gate the TARGET of
 * an `?expand`, so reading a public/low-scoped collection can't pull a scoped collection's rows).
 *
 * An operation is allowed when the collection's policy makes it public, OR the request's api-key
 * scopes satisfy the `collections.{name}.{operation}` capability, OR (no api-key) a session user
 * holds the matching Aegis permission. api-key and session are mutually exclusive: a key's scopes
 * are its sole authority. Fail-closed everywhere.
 *
 * The `collections.` prefix namespaces the capability: without it, a collection named `users`
 * would be gated by the bare `users.read` — silently satisfied by any unrelated `users.*` scope
 * or Aegis permission from another subsystem (fails open on name collision).
 */
final class CollectionAccessResolver
{
    /** The admin data-browser permission; implies every per-collection capability for sessions. */
    private const ADMIN_DATA_PERMISSION = 'collections.data.manage';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AuthenticationManager $auth,
    ) {
    }

    /**
     * True when $request may perform $operation (read|write|delete) on the collection $def (named
     * $name). A null $def is treated as scoped (fail-closed) — the caller resolves the 404.
     */
    public function allows(Request $request, ?CollectionDefinition $def, string $name, string $operation): bool
    {
        $level = $def?->accessPolicy->forOperation($operation) ?? AccessPolicy::SCOPED;
        if ($level === AccessPolicy::PUBLIC) {
            return true;
        }

        $capability = 'collections.' . $name . '.' . $operation;

        // scoped — api-key path (scopes are the sole authority) vs session path, mutually exclusive.
        if ($request->attributes->has('api_key_scopes')) {
            return $this->apiKeyGrants($request, $capability);
        }

        // Session path: the per-collection capability, or the admin data-browser god-mode
        // permission (the same one the admin routes gate on) — so an admin browsing
        // /v1/admin/.../rows?expand=... isn't 403'd on scoped expand targets they can
        // already read directly. Never applies to api keys (scopes above are exhaustive).
        $user = $this->resolveSessionUser($request);

        return $user !== null && (
            $this->userGrants($user, $capability, $request)
            || $this->userGrants($user, self::ADMIN_DATA_PERMISSION, $request)
        );
    }

    /** True when the request's api-key scopes satisfy the capability. */
    private function apiKeyGrants(Request $request, string $capability): bool
    {
        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string',
        ));

        // ApiKeyService::scopeSatisfies() treats an empty grant list as "full access" —
        // the framework's legacy-key semantics. Collections are default-deny: a key with
        // no scopes holds no collection capabilities at all.
        if ($granted === []) {
            return false;
        }

        return ApiKeyService::scopeSatisfies($granted, $capability);
    }

    /**
     * Resolve the session user — from a prior middleware's `user` attribute, else by authenticating
     * the request on demand. Returns null for anonymous/invalid; never throws.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSessionUser(Request $request): ?array
    {
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['uuid'])) {
            return $user;
        }
        try {
            $authenticated = $this->auth->authenticate($request);
            if (!is_array($authenticated) || !isset($authenticated['uuid'])) {
                return null;
            }

            // Memoize onto the request as the standard post-auth attribute: repeat gates
            // (?expand target checks) skip re-authentication, and downstream consumers
            // (ActorResolver, rate limiting by 'user') see the principal.
            $request->attributes->set('user', $authenticated);

            return $authenticated;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the session user holds the Aegis permission for the capability.
     *
     * @param array<string, mixed> $user
     */
    private function userGrants(array $user, string $capability, Request $request): bool
    {
        if (!isset($user['uuid']) || !is_string($user['uuid']) || trim($user['uuid']) === '') {
            return false;
        }
        $manager = $this->permissionManager();
        if ($manager === null) {
            return false;
        }
        $roles = isset($user['roles']) && is_array($user['roles'])
            ? array_values(array_filter($user['roles'], 'is_string'))
            : [];
        try {
            return $manager->can(trim($user['uuid']), $capability, 'thallo', [
                'roles' => $roles,
                'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Resolve Aegis's PermissionManager from the container; null when unavailable (fail-closed). */
    private function permissionManager(): ?PermissionManager
    {
        if (!$this->context->hasContainer()) {
            return null;
        }
        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($manager = $container->get($id)) instanceof PermissionManager) {
                    return $manager;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
