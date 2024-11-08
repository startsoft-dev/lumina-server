# Authorization

Laravel Global Controller uses a convention-based permission system with policies to control access to resources.

## Table of Contents

- [Overview](#overview)
- [Permission System](#permission-system)
- [ResourcePolicy](#resourcepolicy)
- [Creating Policies](#creating-policies)
- [Permission Wildcards](#permission-wildcards)
- [Dynamic Column Hiding](#dynamic-column-hiding)
- [Custom Authorization Logic](#custom-authorization-logic)
- [Testing Authorization](#testing-authorization)

---

## Overview

Authorization in Laravel Global Controller follows a **layered approach**:

| Layer | Purpose | Based On |
|-------|---------|----------|
| **Permissions** | What actions can this user perform? | JSON array in `user_roles.permissions` |
| **Policy** | Can this user do this action on this resource? | Automatically checks permissions via `ResourcePolicy` |
| **Policy (hiddenColumns)** | Which columns can this user see? | Role-based logic |
| **Scope** | Which records can this user access? | Role-based data filtering |

**Key Principles:**
- Permissions are stored as strings: `{model_slug}.{action}`
- Policies automatically enforce permissions using `ResourcePolicy`
- Custom logic can be added by overriding policy methods
- Column visibility controlled via `hiddenColumns()` method

---

## Permission System

### How It Works

1. Each user has roles via the `user_roles` pivot table
2. Each role has a `permissions` JSON column containing permission strings
3. Permission format: `{model_slug}.{action}` (e.g., `posts.index`, `blogs.store`)
4. `ResourcePolicy` automatically checks these permissions
5. Actions map to CRUD operations: `index`, `show`, `store`, `update`, `destroy`

### Database Setup

**Migration for `user_roles` table:**

```php
Schema::create('user_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('role_id')->constrained()->onDelete('cascade');
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->json('permissions')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'role_id', 'organization_id']);
});
```

**UserRole Model:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    protected $fillable = [
        'user_id',
        'role_id',
        'organization_id',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
```

### User Model Setup

Add `hasPermission()` and `userRoles()` to your User model:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    // Relationships
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'user_roles')
            ->withPivot('role_id', 'permissions')
            ->withTimestamps();
    }

    /**
     * Check if user has a specific permission in an organization.
     */
    public function hasPermission(string $permission, $organization = null): bool
    {
        $query = $this->userRoles();

        if ($organization) {
            if (is_object($organization)) {
                $query->where('organization_id', $organization->id);
            } else {
                $query->where('organization_id', $organization);
            }
        }

        $userRoles = $query->get();
        $slug = explode('.', $permission)[0] ?? '';

        foreach ($userRoles as $userRole) {
            $permissions = $userRole->permissions ?? [];

            foreach ($permissions as $p) {
                // Exact match
                if ($p === $permission) {
                    return true;
                }

                // Wildcard: all permissions
                if ($p === '*') {
                    return true;
                }

                // Wildcard: all actions on this model
                if ($p === "{$slug}.*") {
                    return true;
                }
            }
        }

        return false;
    }
}
```

### Assigning Permissions

**Full Access (Admin):**

```php
UserRole::create([
    'user_id' => $user->id,
    'role_id' => $adminRole->id,
    'organization_id' => $org->id,
    'permissions' => ['*'], // All permissions
]);
```

**Specific Model Access:**

```php
UserRole::create([
    'user_id' => $user->id,
    'role_id' => $editorRole->id,
    'organization_id' => $org->id,
    'permissions' => [
        'posts.*',      // All post actions
        'comments.*',   // All comment actions
        'users.index',  // Only list users
        'users.show',   // Only view users
    ],
]);
```

**Read-Only Access:**

```php
UserRole::create([
    'user_id' => $user->id,
    'role_id' => $viewerRole->id,
    'organization_id' => $org->id,
    'permissions' => [
        'posts.index',
        'posts.show',
        'comments.index',
        'comments.show',
    ],
]);
```

---

## ResourcePolicy

The `ResourcePolicy` base class provides automatic permission checking for all CRUD actions.

### Minimal Policy (Zero Boilerplate)

```php
<?php

namespace App\Policies;

use Lumina\LaravelApi\Policies\ResourcePolicy;

class PostPolicy extends ResourcePolicy
{
    // That's it! All CRUD methods automatically check permissions.
    // {slug}.index, {slug}.show, {slug}.store, {slug}.update, {slug}.destroy
}
```

### What ResourcePolicy Provides

**Built-in Methods:**

| Method | Permission Checked | HTTP Method | Endpoint |
|--------|-------------------|-------------|----------|
| `viewAny()` | `{slug}.index` | GET | `/api/{model}` |
| `view()` | `{slug}.show` | GET | `/api/{model}/{id}` |
| `create()` | `{slug}.store` | POST | `/api/{model}` |
| `update()` | `{slug}.update` | PUT/PATCH | `/api/{model}/{id}` |
| `delete()` | `{slug}.destroy` | DELETE | `/api/{model}/{id}` |
| `viewTrashed()` | `{slug}.trashed` | GET | `/api/{model}/trashed` |
| `restore()` | `{slug}.restore` | POST | `/api/{model}/{id}/restore` |
| `forceDelete()` | `{slug}.forceDelete` | DELETE | `/api/{model}/{id}/force-delete` |

**Each method:**
1. Checks if user is authenticated (returns false if null and action requires auth)
2. Calls `hasPermission($permission, $organization)` on the user
3. Returns boolean result

### Slug Resolution

The policy automatically resolves the model slug from `config('global-controller.models')`.

**Manual override:**

```php
class PostPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';
}
```

---

## Creating Policies

### Generate Policy

```bash
php artisan make:policy PostPolicy --model=Post
```

### Extend ResourcePolicy

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;
use Lumina\LaravelApi\Policies\ResourcePolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class PostPolicy extends ResourcePolicy
{
    // Inherits all CRUD permission checks from ResourcePolicy
}
```

### Register Policy

In `app/Providers/AuthServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\Post;
use App\Policies\PostPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Post::class => PostPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
```

---

## Permission Wildcards

### Wildcard Types

| Permission | Grants Access To |
|-----------|-----------------|
| `*` | **Everything** - all models, all actions |
| `posts.*` | **All actions** on posts (index, show, store, update, destroy) |
| `posts.index` | **Specific action** - only list posts |

### Examples

**Admin with full access:**

```php
'permissions' => ['*']
```

**Editor with post and comment access:**

```php
'permissions' => ['posts.*', 'comments.*']
```

**Viewer with read-only access:**

```php
'permissions' => ['posts.index', 'posts.show', 'comments.index', 'comments.show']
```

**Mixed permissions:**

```php
'permissions' => [
    'posts.*',          // Full access to posts
    'comments.index',   // Only list comments
    'comments.show',    // Only view comments
    'users.index',      // Only list users
]
```

---

## Dynamic Column Hiding

Control which columns are visible based on user role/permissions.

### Basic Example

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Post;
use Lumina\LaravelApi\Policies\ResourcePolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class PostPolicy extends ResourcePolicy
{
    /**
     * Define columns to hide based on user.
     */
    public function hiddenColumns(?Authenticatable $user): array
    {
        // Not authenticated - hide sensitive fields
        if (!$user) {
            return ['user_id', 'internal_notes'];
        }

        $organization = request()->get('organization');

        // Get user's role in this organization
        $userRole = $user->userRoles()
            ->where('organization_id', $organization?->id)
            ->with('role')
            ->first();

        if (!$userRole) {
            return ['user_id', 'internal_notes'];
        }

        $roleSlug = $userRole->role->slug ?? '';

        // Admin sees everything
        if ($roleSlug === 'admin') {
            return [];
        }

        // Editor sees most fields
        if ($roleSlug === 'editor') {
            return ['internal_notes'];
        }

        // Viewer sees limited fields
        return ['user_id', 'internal_notes', 'draft_content'];
    }
}
```

### How It Works

1. The `HidableColumns` trait on your model calls this method during serialization
2. Returned columns are merged with model's `$hidden` array
3. Hidden columns are removed from JSON responses
4. Results cached per request to avoid N+1 queries

### Hide Virtual Attributes

Works with computed attributes too:

```php
// Model
protected $appends = ['rank'];

protected function rank(): Attribute
{
    return Attribute::make(
        get: fn () => $this->calculateRank()
    );
}

// Policy
public function hiddenColumns(?Authenticatable $user): array
{
    if ($user && $user->hasRole('assistant')) {
        return ['rank']; // Hide computed attribute
    }

    return [];
}
```

---

## Custom Authorization Logic

### Override Specific Methods

Add custom logic while keeping permission checks:

```php
class PostPolicy extends ResourcePolicy
{
    /**
     * Users can only update their own posts.
     */
    public function update(?Authenticatable $user, Post $post): bool
    {
        // First check permissions
        if (!parent::update($user, $post)) {
            return false;
        }

        // Then check ownership
        return $user->id === $post->user_id;
    }

    /**
     * Users can only delete their own posts.
     */
    public function delete(?Authenticatable $user, Post $post): bool
    {
        if (!parent::delete($user, $post)) {
            return false;
        }

        return $user->id === $post->user_id;
    }
}
```

### Complete Custom Logic

Ignore permission system for a specific action:

```php
class OrganizationPolicy extends ResourcePolicy
{
    /**
     * Users can only view organizations they belong to.
     */
    public function view(?Authenticatable $user, Organization $organization): bool
    {
        // Custom logic only - no permission check
        if (!$user) {
            return false;
        }

        return $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();
    }

    /**
     * But still use permission system for other actions.
     */
    public function update(?Authenticatable $user, Organization $organization): bool
    {
        return parent::update($user, $organization);
    }
}
```

### Public Access

Make specific models publicly accessible:

```php
class PostPolicy extends ResourcePolicy
{
    /**
     * Anyone can list published posts.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return true; // Public access
    }

    /**
     * Anyone can view published posts, auth required for drafts.
     */
    public function view(?Authenticatable $user, Post $post): bool
    {
        // Published posts are public
        if ($post->is_published) {
            return true;
        }

        // Drafts require authentication and ownership
        return $user && $user->id === $post->user_id;
    }

    /**
     * Creating posts requires authentication and permission.
     */
    public function create(?Authenticatable $user): bool
    {
        return parent::create($user);
    }
}
```

---

## Testing Authorization

### Feature Tests

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Post;
use App\Models\Role;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_all_actions()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create(['slug' => 'admin']);
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        $token = $user->createToken('test')->plainTextToken;

        // Can list
        $response = $this->withToken($token)
            ->getJson("/api/{$org->slug}/posts");
        $response->assertOk();

        // Can create
        $response = $this->withToken($token)
            ->postJson("/api/{$org->slug}/posts", [
                'title' => 'Test',
                'content' => 'Content',
            ]);
        $response->assertCreated();
    }

    public function test_viewer_cannot_create_posts()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create(['slug' => 'viewer']);
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.index', 'posts.show'],
        ]);

        $token = $user->createToken('test')->plainTextToken;

        // Can list
        $response = $this->withToken($token)
            ->getJson("/api/{$org->slug}/posts");
        $response->assertOk();

        // Cannot create
        $response = $this->withToken($token)
            ->postJson("/api/{$org->slug}/posts", [
                'title' => 'Test',
                'content' => 'Content',
            ]);
        $response->assertForbidden();
    }

    public function test_user_can_only_update_own_posts()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create(['slug' => 'editor']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.*'],
        ]);

        $ownPost = Post::factory()->create(['user_id' => $user->id]);
        $otherPost = Post::factory()->create(['user_id' => $otherUser->id]);

        $token = $user->createToken('test')->plainTextToken;

        // Can update own post
        $response = $this->withToken($token)
            ->putJson("/api/{$org->slug}/posts/{$ownPost->id}", [
                'title' => 'Updated',
            ]);
        $response->assertOk();

        // Cannot update other's post
        $response = $this->withToken($token)
            ->putJson("/api/{$org->slug}/posts/{$otherPost->id}", [
                'title' => 'Updated',
            ]);
        $response->assertForbidden();
    }

    public function test_hidden_columns_are_filtered()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create(['slug' => 'viewer']);
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.show'],
        ]);

        $post = Post::factory()->create([
            'internal_notes' => 'Secret notes',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/{$org->slug}/posts/{$post->id}");

        $response->assertOk()
            ->assertJsonMissing(['internal_notes' => 'Secret notes']);
    }
}
```

### Unit Tests

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Role;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_wildcard_grants_all_permissions()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create();
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertTrue($user->hasPermission('posts.store', $org));
        $this->assertTrue($user->hasPermission('comments.destroy', $org));
    }

    public function test_model_wildcard_grants_all_actions_on_model()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create();
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.*'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertTrue($user->hasPermission('posts.store', $org));
        $this->assertTrue($user->hasPermission('posts.destroy', $org));

        $this->assertFalse($user->hasPermission('comments.index', $org));
    }

    public function test_specific_permission_only_grants_that_action()
    {
        $org = Organization::factory()->create();
        $role = Role::factory()->create();
        $user = User::factory()->create();

        $user->userRoles()->create([
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.index', 'posts.show'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertTrue($user->hasPermission('posts.show', $org));

        $this->assertFalse($user->hasPermission('posts.store', $org));
        $this->assertFalse($user->hasPermission('posts.update', $org));
    }
}
```

---

## Best Practices

### 1. Start with ResourcePolicy

Always extend `ResourcePolicy` to get automatic permission checking:

```php
// ✅ Good
class PostPolicy extends ResourcePolicy
{
    // Custom logic here
}

// ❌ Bad - reimplementing permission checks
class PostPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user && $user->hasPermission('posts.index');
    }
    // ... manual implementation for each method
}
```

### 2. Compose, Don't Replace

Add custom logic on top of permission checks:

```php
// ✅ Good - check permissions first, then ownership
public function update(?Authenticatable $user, Post $post): bool
{
    if (!parent::update($user, $post)) {
        return false;
    }

    return $user->id === $post->user_id;
}

// ❌ Bad - only checking ownership, bypassing permissions
public function update(?Authenticatable $user, Post $post): bool
{
    return $user && $user->id === $post->user_id;
}
```

### 3. Use Wildcards Wisely

```php
// ✅ Good - admin gets everything
'permissions' => ['*']

// ✅ Good - editor gets specific models
'permissions' => ['posts.*', 'comments.*']

// ❌ Bad - mixing wildcards unnecessarily
'permissions' => ['posts.*', 'posts.index', 'posts.show']
// 'posts.*' already includes index and show
```

### 4. Test Each Role

Write tests for each role's permissions:

```php
public function test_each_role_has_correct_permissions()
{
    $roles = ['admin', 'editor', 'viewer'];

    foreach ($roles as $roleSlug) {
        $this->assertRolePermissions($roleSlug);
    }
}
```

### 5. Document Permissions

Document required permissions in policy comments:

```php
/**
 * Post Policy
 *
 * Permissions:
 * - posts.index: List all posts
 * - posts.show: View single post
 * - posts.store: Create post (requires ownership)
 * - posts.update: Update post (requires ownership)
 * - posts.destroy: Delete post (requires ownership)
 */
class PostPolicy extends ResourcePolicy
{
    // ...
}
```

---

## Related Documentation

- [API Reference - Authorization](../API.md#error-responses)
- [Authentication](./authentication.md) - User login and tokens
- [Multi-Tenancy](./multi-tenancy.md) - Organization-based isolation
- [Getting Started](../getting-started.md)
