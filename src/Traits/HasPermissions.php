<?php

namespace Lumina\LaravelApi\Traits;

use App\Models\Organization;
use App\Models\UserRole;

trait HasPermissions
{
    /**
     * Get the user role assignments.
     */
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Check if the user has a specific permission.
     *
     * Permission format: '{slug}.{action}' (e.g., 'posts.index', 'blogs.store')
     *
     * Supports wildcards:
     *   - '*' grants access to everything
     *   - 'posts.*' grants access to all actions on posts
     *
     * When an organization is provided, only checks permissions for that organization.
     * When no organization is provided, checks permissions across all organizations.
     *
     * @param  string  $permission  The permission to check (e.g., 'posts.index')
     * @param  \App\Models\Organization|null  $organization  The organization context
     * @return bool
     */
    public function hasPermission(string $permission, $organization = null): bool
    {
        $query = $this->userRoles();

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        $userRoles = $query->get();

        // Extract the resource slug from the permission (e.g., 'posts' from 'posts.index')
        $slug = explode('.', $permission)[0] ?? '';

        foreach ($userRoles as $userRole) {
            $permissions = $userRole->permissions ?? [];

            foreach ($permissions as $p) {
                // Exact match
                if ($p === $permission) {
                    return true;
                }

                // Wildcard: full access
                if ($p === '*') {
                    return true;
                }

                // Wildcard: resource-level (e.g., 'posts.*' matches 'posts.index')
                if ($p === "{$slug}.*") {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the role slug for the given organization (for role-based validation).
     *
     * @param  \App\Models\Organization|mixed  $organization  Organization context from request, or null
     * @return string|null  Role slug (e.g. 'admin', 'assistant'), or null to use wildcard/fallback
     */
    public function getRoleSlugForValidation($organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        $organizationId = $organization instanceof Organization
            ? $organization->id
            : (is_object($organization) && isset($organization->id) ? $organization->id : null);

        if ($organizationId === null) {
            return null;
        }

        $userRole = $this->userRoles()
            ->where('organization_id', $organizationId)
            ->with('role')
            ->first();

        return $userRole?->role?->slug;
    }
}
