# Multi-Tenancy

Laravel Global Controller supports organization-based multi-tenancy, allowing multiple organizations to share the same application with isolated data and permissions.

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Database Setup](#database-setup)
- [Route Configuration](#route-configuration)
- [Organization Context](#organization-context)
- [Permission Scoping](#permission-scoping)
- [Data Isolation](#data-isolation)
- [Middleware](#middleware)
- [Best Practices](#best-practices)

---

## Overview

Multi-tenancy enables multiple organizations (tenants) to use the same application instance while keeping their data completely separate.

**Key Concepts:**
- **Organization** - A tenant in the system (company, team, workspace)
- **Organization Slug** - URL-safe identifier (e.g., "acme-corp")
- **Organization ID** - Primary key for data relationships
- **User Roles** - Scoped to organizations (user can have different roles in different orgs)
- **Permissions** - Scoped to organizations via user_roles table

**Features:**
- Data isolation per organization
- Organization-specific permissions
- Route prefixes or subdomains
- Automatic organization context in requests
- Cross-organization user access (users can belong to multiple orgs)

**Use Cases:**
- SaaS applications with multiple companies
- Team-based collaboration tools
- White-label platforms
- B2B applications

---

## Architecture

### Multi-Tenant Data Flow

```
1. User logs in → receives token + organization_slug
2. Client includes organization in URL: /api/{organization}/posts
3. Middleware resolves organization from slug
4. Organization added to request context
5. Permissions checked for user in that organization
6. Data filtered to organization's records
7. Response returned with organization context
```

### Database Relationships

```
organizations (id, slug, name)
    ↓
users ←→ user_roles (user_id, role_id, organization_id, permissions)
    ↓
roles (id, name, slug)

posts (id, organization_id, ...)
comments (id, organization_id, ...)
[all tenant data models]
```

### Key Points

- **users** table is global (users can belong to multiple organizations)
- **user_roles** pivot table links users to organizations with roles
- **permissions** JSON array in user_roles, scoped to that organization
- **Data models** have organization_id foreign key for isolation

---

## Database Setup

### Organizations Table

Create the organizations table:

```bash
php artisan make:migration create_organizations_table
```

**Migration:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // URL-safe identifier
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
```

### UserRoles Table (Pivot)

Links users to organizations with roles:

```php
Schema::create('user_roles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('role_id')->constrained()->onDelete('cascade');
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->json('permissions')->nullable(); // Organization-specific permissions
    $table->timestamps();

    $table->unique(['user_id', 'role_id', 'organization_id']);
});
```

### Adding organization_id to Models

Add organization_id to all tenant data models:

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->string('title');
    $table->text('content');
    $table->foreignId('user_id')->constrained();
    $table->timestamps();
});

Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade');
    $table->foreignId('post_id')->constrained()->onDelete('cascade');
    $table->text('content');
    $table->foreignId('user_id')->constrained();
    $table->timestamps();
});
```

**Important:**
- Always use `->constrained()->onDelete('cascade')`
- Ensures data is deleted when organization is deleted
- Maintains referential integrity

### Organization Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'logo',
        'is_active',
    ];

    // Users in this organization
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('role_id', 'permissions')
            ->withTimestamps();
    }

    // User roles in this organization
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    // Example: all posts in this organization
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

### User Model Updates

Add organization relationships:

```php
class User extends Authenticatable
{
    // User's roles in all organizations
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    // Organizations this user belongs to
    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'user_roles')
            ->withPivot('role_id', 'permissions')
            ->withTimestamps();
    }

    /**
     * Check if user has permission in specific organization.
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
                if ($p === $permission || $p === '*' || $p === "{$slug}.*") {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get user's primary organization.
     */
    public function currentOrganization()
    {
        return $this->organizations()->first();
    }
}
```

---

## Route Configuration

### Method 1: Route Prefix (Recommended)

Use organization slug as URL prefix:

```
/api/acme-corp/posts
/api/acme-corp/posts/1
/api/tech-startup/posts
```

**Setup routes/api.php:**

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetOrganizationContext;

// Multi-tenant routes with organization prefix
Route::prefix('api/{organization}')
    ->middleware(['api', SetOrganizationContext::class])
    ->group(function () {
        // Auth routes
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum');

        // Invitation routes
        Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{id}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{id}', [InvitationController::class, 'cancel']);
        });

        // Nested operations
        Route::post('/nested', [GlobalController::class, 'nested'])
            ->middleware('auth:sanctum');

        // Your auto-generated model routes will be here
        // Registered by GlobalController
    });

// Global routes (no organization context)
Route::prefix('api')
    ->middleware('api')
    ->group(function () {
        // Password reset (no organization needed)
        Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);
    });
```

### Method 2: Subdomain

Use subdomain for organization:

```
https://acme-corp.yourdomain.com/api/posts
https://tech-startup.yourdomain.com/api/posts
```

**Setup routes/api.php:**

```php
Route::domain('{organization}.yourdomain.com')
    ->middleware(['api', SetOrganizationContext::class])
    ->group(function () {
        // Your routes here
    });
```

**Configure .env:**

```
APP_URL=https://yourdomain.com
SESSION_DOMAIN=.yourdomain.com
SANCTUM_STATEFUL_DOMAINS=*.yourdomain.com
```

### Comparison

| Method | Example URL | Pros | Cons |
|--------|-------------|------|------|
| **Prefix** | `/api/acme-corp/posts` | Simple setup, works on any domain, easier local dev | Longer URLs |
| **Subdomain** | `acme-corp.yourdomain.com/api/posts` | Shorter URLs, feels more "native" | Requires wildcard DNS, SSL certificates for each subdomain |

**Recommendation:** Use prefix for most applications. Use subdomain for white-label or if client preference justifies the extra setup.

---

## Organization Context

### Middleware: SetOrganizationContext

Create middleware to resolve organization from URL:

```bash
php artisan make:middleware SetOrganizationContext
```

**Implementation:**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organization;

class SetOrganizationContext
{
    public function handle(Request $request, Closure $next)
    {
        // Get organization slug from route parameter
        $organizationSlug = $request->route('organization');

        if (!$organizationSlug) {
            return response()->json([
                'message' => 'Organization context required'
            ], 400);
        }

        // Find organization by slug
        $organization = Organization::where('slug', $organizationSlug)
            ->where('is_active', true)
            ->first();

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found'
            ], 404);
        }

        // Add to request for use in controllers/policies
        $request->merge(['organization' => $organization]);

        // Also set in config for global access
        config(['app.current_organization' => $organization]);

        return $next($request);
    }
}
```

**Register in bootstrap/app.php:**

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'organization' => \App\Http\Middleware\SetOrganizationContext::class,
    ]);
})
```

### Accessing Organization in Controllers

**From request:**
```php
public function index(Request $request)
{
    $organization = $request->input('organization');
    // or
    $organization = $request->get('organization');

    // Use organization context
    $posts = Post::where('organization_id', $organization->id)->get();
}
```

**From config:**
```php
public function index()
{
    $organization = config('app.current_organization');

    // Use organization context
}
```

**Helper function (optional):**
```php
// app/Helpers/helpers.php
if (!function_exists('current_organization')) {
    function current_organization()
    {
        return config('app.current_organization');
    }
}

// Usage
$organization = current_organization();
```

---

## Permission Scoping

### Organization-Specific Permissions

Permissions are stored in `user_roles.permissions` JSON column, scoped to each organization:

```php
// User is admin in Acme Corp
UserRole::create([
    'user_id' => 1,
    'role_id' => $adminRole->id,
    'organization_id' => 1, // Acme Corp
    'permissions' => ['*'], // All permissions in Acme Corp
]);

// Same user is contributor in Tech Startup
UserRole::create([
    'user_id' => 1,
    'role_id' => $contributorRole->id,
    'organization_id' => 2, // Tech Startup
    'permissions' => ['posts.index', 'posts.store'], // Limited in Tech Startup
]);
```

### Checking Permissions with Organization

**In policies:**
```php
public function viewAny(?Authenticatable $user): bool
{
    if (!$user) {
        return false;
    }

    $organization = current_organization();

    return $user->hasPermission('posts.index', $organization);
}

public function create(?Authenticatable $user): bool
{
    if (!$user) {
        return false;
    }

    $organization = current_organization();

    return $user->hasPermission('posts.store', $organization);
}
```

**In controllers:**
```php
public function index(Request $request)
{
    $organization = $request->get('organization');
    $user = $request->user();

    if (!$user->hasPermission('posts.index', $organization)) {
        abort(403, 'You do not have permission to view posts in this organization');
    }

    // Continue with request
}
```

### Cross-Organization Access

Users can belong to multiple organizations with different roles:

```php
// Get all organizations for a user
$organizations = $user->organizations;

// Get user's role in specific organization
$userRole = $user->userRoles()
    ->where('organization_id', $organization->id)
    ->first();

$role = $userRole->role; // Role object
$permissions = $userRole->permissions; // Permissions array
```

---

## Data Isolation

### Automatic Scoping with Global Scopes

Create a global scope to automatically filter by organization:

```bash
php artisan make:scope OrganizationScope
```

**Implementation:**

```php
<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $organization = current_organization();

        if ($organization) {
            $builder->where($model->getTable() . '.organization_id', $organization->id);
        }
    }
}
```

**Apply to models:**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\OrganizationScope;

class Post extends Model
{
    protected static function booted()
    {
        static::addGlobalScope(new OrganizationScope);

        // Automatically set organization_id on create
        static::creating(function ($model) {
            if (!$model->organization_id) {
                $organization = current_organization();
                if ($organization) {
                    $model->organization_id = $organization->id;
                }
            }
        });
    }
}
```

**Result:**
```php
// Automatically filtered by current organization
$posts = Post::all();
// SELECT * FROM posts WHERE organization_id = 1

// Manually query all organizations (use withoutGlobalScope)
$allPosts = Post::withoutGlobalScope(OrganizationScope::class)->get();
```

### Manual Scoping

If you prefer manual control:

```php
class Post extends Model
{
    // Don't use global scope

    // But still auto-set organization_id
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->organization_id) {
                $organization = current_organization();
                if ($organization) {
                    $model->organization_id = $organization->id;
                }
            }
        });
    }
}

// In controllers, always scope manually
public function index(Request $request)
{
    $organization = $request->get('organization');

    $posts = Post::where('organization_id', $organization->id)->get();

    return response()->json($posts);
}
```

**Trade-offs:**
- **Global scope:** Automatic filtering, less code, harder to accidentally leak data
- **Manual scope:** More explicit, easier to debug, more verbose

---

## Middleware

### Rate Limiting per Organization

```php
use Illuminate\Support\Facades\RateLimiter;

// In AppServiceProvider boot()
RateLimiter::for('per-organization', function (Request $request) {
    $organization = $request->get('organization');

    return Limit::perMinute(100)->by($organization?->id ?? 'guest');
});

// Apply to routes
Route::middleware('throttle:per-organization')->group(function () {
    // Your routes
});
```

### Organization Verification

Ensure user belongs to organization:

```bash
php artisan make:middleware EnsureUserBelongsToOrganization
```

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserBelongsToOrganization
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $organization = $request->get('organization');

        if (!$user || !$organization) {
            return $next($request);
        }

        // Check if user belongs to this organization
        $belongsToOrg = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();

        if (!$belongsToOrg) {
            return response()->json([
                'message' => 'You do not have access to this organization'
            ], 403);
        }

        return $next($request);
    }
}
```

**Apply to routes:**
```php
Route::middleware(['auth:sanctum', 'organization', 'user-belongs-to-org'])
    ->group(function () {
        // Only users who belong to the organization can access
    });
```

---

## Best Practices

### 1. Always Validate Organization Context

```php
// ✅ Good - validate organization exists
public function index(Request $request)
{
    $organization = $request->get('organization');

    if (!$organization) {
        abort(400, 'Organization context required');
    }

    // Continue
}

// ❌ Bad - assume organization exists
public function index(Request $request)
{
    $organization = $request->get('organization');
    $posts = Post::where('organization_id', $organization->id)->get();
}
```

### 2. Use Global Scopes for Data Isolation

```php
// ✅ Good - automatic filtering
class Post extends Model
{
    protected static function booted()
    {
        static::addGlobalScope(new OrganizationScope);
    }
}

// ❌ Bad - easy to forget filtering
class Post extends Model
{
    // No global scope
}

// Risky: could return posts from all organizations
$posts = Post::all();
```

### 3. Test Cross-Organization Data Leakage

```php
public function test_user_cannot_access_other_organization_data()
{
    $org1 = Organization::factory()->create(['slug' => 'org1']);
    $org2 = Organization::factory()->create(['slug' => 'org2']);

    $user = User::factory()->create();
    UserRole::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $org1->id,
        'permissions' => ['posts.*'],
    ]);

    $postInOrg2 = Post::factory()->create(['organization_id' => $org2->id]);

    // User tries to access org2's post
    $response = $this->actingAs($user)
        ->getJson("/api/org2/posts/{$postInOrg2->id}");

    // Should be denied
    $response->assertStatus(403);
}
```

### 4. Log Organization Context

```php
// ✅ Good - include organization in logs
Log::info('Post created', [
    'post_id' => $post->id,
    'user_id' => auth()->id(),
    'organization_id' => $organization->id,
    'organization_slug' => $organization->slug,
]);

// ❌ Bad - no organization context
Log::info('Post created', [
    'post_id' => $post->id,
]);
```

### 5. Handle Missing Organization Gracefully

```php
// ✅ Good - clear error message
if (!$organization) {
    return response()->json([
        'message' => 'Organization not found or inactive',
        'slug' => $request->route('organization')
    ], 404);
}

// ❌ Bad - generic error
if (!$organization) {
    abort(404);
}
```

### 6. Use Cascading Deletes

```php
// ✅ Good - cascading delete
Schema::table('posts', function (Blueprint $table) {
    $table->foreignId('organization_id')
        ->constrained()
        ->onDelete('cascade'); // Deletes posts when org deleted
});

// ❌ Bad - orphaned data
Schema::table('posts', function (Blueprint $table) {
    $table->foreignId('organization_id')->constrained();
    // No onDelete - leaves orphaned posts
});
```

### 7. Seed Organizations for Testing

```php
// database/seeders/OrganizationSeeder.php
public function run()
{
    $org1 = Organization::create([
        'slug' => 'acme-corp',
        'name' => 'Acme Corporation',
        'is_active' => true,
    ]);

    $org2 = Organization::create([
        'slug' => 'tech-startup',
        'name' => 'Tech Startup Inc',
        'is_active' => true,
    ]);

    // Create admin user for each org
    $admin = User::factory()->create(['email' => 'admin@acme-corp.com']);
    UserRole::create([
        'user_id' => $admin->id,
        'role_id' => Role::where('slug', 'admin')->first()->id,
        'organization_id' => $org1->id,
        'permissions' => ['*'],
    ]);
}
```

### 8. Document Organization Requirements

```php
/**
 * Post Model
 *
 * Multi-Tenancy:
 * - Belongs to organization (required)
 * - Filtered by OrganizationScope automatically
 * - organization_id set automatically on create
 * - Cascading delete when organization deleted
 */
class Post extends Model
{
    use HasValidation;

    protected static function booted()
    {
        static::addGlobalScope(new OrganizationScope);
    }
}
```

### 9. Provide Organization Switcher

```php
// API endpoint to switch organization context
public function switchOrganization(Request $request)
{
    $request->validate([
        'organization_slug' => 'required|exists:organizations,slug',
    ]);

    $user = $request->user();
    $organization = Organization::where('slug', $request->organization_slug)->first();

    // Verify user belongs to organization
    $belongsToOrg = $user->organizations()
        ->where('organizations.id', $organization->id)
        ->exists();

    if (!$belongsToOrg) {
        return response()->json([
            'message' => 'You do not have access to this organization'
        ], 403);
    }

    // Return new organization context
    return response()->json([
        'organization' => [
            'id' => $organization->id,
            'slug' => $organization->slug,
            'name' => $organization->name,
        ],
        'message' => 'Organization switched successfully'
    ]);
}
```

### 10. Monitor Organization Activity

```php
// Track organization usage
public function recordActivity($action, $model, $organization)
{
    OrganizationActivity::create([
        'organization_id' => $organization->id,
        'action' => $action,
        'model_type' => get_class($model),
        'model_id' => $model->id,
        'user_id' => auth()->id(),
        'ip_address' => request()->ip(),
        'created_at' => now(),
    ]);
}

// Use in events
Post::created(function ($post) {
    recordActivity('created', $post, current_organization());
});
```

---

## Related Documentation

- [Authorization](./authorization.md) - Organization-scoped permissions
- [Authentication](./authentication.md) - Login returns organization_slug
- [Audit Trail](./audit-trail.md) - Tracks organization_id in logs
- [Invitations](./invitations.md) - Organization user invitations
- [Getting Started](../getting-started.md)
