<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Lumina\LaravelApi\Contracts\HasRoleBasedValidation;

class User extends Authenticatable implements HasRoleBasedValidation
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'user_roles')
            ->withPivot('role_id')
            ->withTimestamps();
    }

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
     * Supports wildcards: '*' (all), 'posts.*' (all actions on posts).
     */
    public function hasPermission(string $permission, $organization = null): bool
    {
        $query = $this->userRoles();

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        $userRoles = $query->get();
        $slug = explode('.', $permission)[0] ?? '';

        foreach ($userRoles as $userRole) {
            $permissions = $userRole->permissions ?? [];

            foreach ($permissions as $p) {
                if ($p === $permission || $p === '*' || $p === "{$slug}.*") {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the role slug for the given organization (for role-based validation).
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
