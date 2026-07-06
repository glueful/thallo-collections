<?php

declare(strict_types=1);

namespace Thallo\Collections\Http;

use Glueful\Auth\UserIdentity;
use Thallo\Collections\Data\Actor;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the caller's identity to a typed Actor from request attributes.
 *
 * Attribute contract (set by the middleware chain that runs before controllers):
 *
 *   auth_method    'api_key' when OptionalApiKeyAuthMiddleware authenticated a key.
 *   api_key_uuid   The key's own UUID (set alongside auth_method='api_key').
 *   user_id        The owning user's UUID (for api_key auth, the key's user_uuid;
 *                  for JWT sessions, the logged-in user's UUID).
 *   user_data      The user array, including a 'roles' list<string> for JWT sessions.
 *
 * Resolution order:
 *   1. api_key auth   → Actor('api_key', $api_key_uuid)
 *   2. Session + admin role → Actor('admin', $user_uuid)
 *   3. Session, no admin  → Actor('user', $user_uuid)
 *   4. Not authenticated   → Actor('user', null)  [scope middleware rejects before here]
 */
final class ActorResolver
{
    private const ADMIN_ROLE = 'administrator';

    /**
     * Resolve the request's authenticated actor.
     *
     * The CollectionScopeMiddleware guarantees that requests without a valid scoped API key
     * never reach the controller, so in practice only the api_key branch is hit for public
     * collections routes. The session branches are included for completeness and future use.
     */
    public function resolve(Request $request): Actor
    {
        $authMethod = (string) ($request->attributes->get('auth_method') ?? '');

        if ($authMethod === 'api_key') {
            $keyUuid = (string) ($request->attributes->get('api_key_uuid') ?? '');
            return new Actor('api_key', $keyUuid !== '' ? $keyUuid : null);
        }

        // Session-based auth (JWT or similar): derive type from the user's roles.
        // Prefer the UserIdentity set by AuthMiddleware on 'auth.user'; fall back to
        // the plain 'user' array, then to the provider-level 'user_data'/'user_id'
        // attributes — on public routes no auth middleware runs, and the on-demand
        // JWT authentication (CollectionAccessResolver) sets only the provider pair,
        // which previously left scoped JWT writes stamped created_by_id = NULL.
        $identity = $request->attributes->get('auth.user');

        if ($identity instanceof UserIdentity) {
            $userUuid = $identity->uuid();
            $roles    = $identity->roles();
        } else {
            $userData = (array) ($request->attributes->get('user') ?? []);
            if ($userData === []) {
                $userData = (array) ($request->attributes->get('user_data') ?? []);
            }
            $userUuid = (string) ($userData['uuid'] ?? '');
            if ($userUuid === '') {
                $userUuid = (string) ($request->attributes->get('user_id') ?? '');
            }
            $roles = (array) ($userData['roles'] ?? []);
        }

        // JWT user arrays often carry no 'roles' (the Aegis enricher is optional); the
        // provider-level is_admin flag is the fallback signal for admin stamping.
        $isAdmin = in_array(self::ADMIN_ROLE, $roles, true)
            || (bool) (($userData ?? [])['is_admin'] ?? false);

        return new Actor(
            $isAdmin ? 'admin' : 'user',
            $userUuid !== '' ? $userUuid : null,
        );
    }
}
