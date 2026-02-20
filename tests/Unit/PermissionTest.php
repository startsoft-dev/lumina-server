<?php

namespace Lumina\LaravelApi\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Lumina\LaravelApi\Policies\ResourcePolicy;
use Lumina\LaravelApi\Tests\TestCase;
use Lumina\LaravelApi\Traits\HasValidation;
use Lumina\LaravelApi\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class PermissionPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'permission_posts';
    protected $fillable = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

/**
 * Policy that uses default ResourcePolicy behavior (convention-based permissions).
 */
class PermissionPostPolicy extends ResourcePolicy
{
    // Uses $resourceSlug auto-resolution from config
}

/**
 * Policy with explicit resource slug.
 */
class ExplicitSlugPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';
}

/**
 * Policy that overrides a method and composes with parent.
 */
class OverrideWithParentPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';

    /**
     * Custom delete: only allow if user owns the post AND has permission.
     */
    public function delete(?Authenticatable $user, $model): bool
    {
        if (!parent::delete($user, $model)) {
            return false;
        }

        // Additional check: user must own the post
        return $user->getAuthIdentifier() === ($model->user_id ?? null);
    }
}

/**
 * Policy that fully overrides a method (ignores permissions).
 */
class FullOverridePolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';

    /**
     * Anyone authenticated can view, regardless of permissions.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class PermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('permission_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Helper: create a user, role, org, and assign permissions.
     */
    protected function createUserWithPermissions(array $permissions, int $userId = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $userId],
            [
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.com",
                'password' => bcrypt('password'),
            ]
        );

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test Org',
                'slug' => 'test-org',
            ]
        );

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test Role',
                'slug' => 'test-role',
            ]
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => $permissions,
        ]);

        // Set organization on request for policy resolution
        request()->merge(['organization' => $org]);

        return $user;
    }

    /**
     * Helper: create a user with no permissions.
     */
    protected function createUserWithoutPermissions(int $userId = 2): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'id' => $userId,
            'name' => "User {$userId}",
            'email' => "user{$userId}@example.com",
            'password' => bcrypt('password'),
        ]);

        return $user;
    }

    // ------------------------------------------------------------------
    // Basic permission checks
    // ------------------------------------------------------------------

    public function test_user_with_exact_permission_is_allowed(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        // Has posts.index but NOT posts.store
        $this->assertFalse($policy->create($user));
    }

    public function test_guest_user_is_denied(): void
    {
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertFalse($policy->viewAny(null));
        $this->assertFalse($policy->view(null, new PermissionPost()));
        $this->assertFalse($policy->create(null));
        $this->assertFalse($policy->update(null, new PermissionPost()));
        $this->assertFalse($policy->delete(null, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // Wildcard permissions
    // ------------------------------------------------------------------

    public function test_wildcard_grants_all_access(): void
    {
        $user = $this->createUserWithPermissions(['*']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, new PermissionPost()));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, new PermissionPost()));
        $this->assertTrue($policy->delete($user, new PermissionPost()));
    }

    public function test_resource_wildcard_grants_all_actions_on_resource(): void
    {
        $user = $this->createUserWithPermissions(['posts.*']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, new PermissionPost()));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, new PermissionPost()));
        $this->assertTrue($policy->delete($user, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // Individual action permissions
    // ------------------------------------------------------------------

    public function test_each_action_maps_to_correct_permission(): void
    {
        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $post = new PermissionPost();

        // Test each action individually
        $actionMap = [
            'viewAny' => 'posts.index',
            'view' => 'posts.show',
            'create' => 'posts.store',
            'update' => 'posts.update',
            'delete' => 'posts.destroy',
        ];

        foreach ($actionMap as $method => $permission) {
            // Create a fresh user with only this permission
            \App\Models\UserRole::query()->delete();
            \App\Models\User::query()->delete();

            $user = $this->createUserWithPermissions([$permission]);
            $policy = new ExplicitSlugPolicy();

            // The method with the matching permission should pass
            $args = in_array($method, ['viewAny', 'create']) ? [$user] : [$user, $post];
            $this->assertTrue(
                $policy->$method(...$args),
                "Expected {$method} to be allowed with permission '{$permission}'"
            );

            // Other methods should fail (they don't have the permission)
            foreach ($actionMap as $otherMethod => $otherPermission) {
                if ($otherMethod === $method) {
                    continue;
                }
                $otherArgs = in_array($otherMethod, ['viewAny', 'create']) ? [$user] : [$user, $post];
                $this->assertFalse(
                    $policy->$otherMethod(...$otherArgs),
                    "Expected {$otherMethod} to be denied when only '{$permission}' is granted"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Multiple permissions
    // ------------------------------------------------------------------

    public function test_user_with_multiple_permissions(): void
    {
        $user = $this->createUserWithPermissions(['posts.index', 'posts.show', 'posts.store']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();
        $post = new PermissionPost();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $post));
        $this->assertTrue($policy->create($user));
        $this->assertFalse($policy->update($user, $post)); // not granted
        $this->assertFalse($policy->delete($user, $post)); // not granted
    }

    // ------------------------------------------------------------------
    // User without any user_roles
    // ------------------------------------------------------------------

    public function test_user_without_user_roles_is_denied(): void
    {
        $user = $this->createUserWithoutPermissions();

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
    }

    // ------------------------------------------------------------------
    // Policy override patterns
    // ------------------------------------------------------------------

    public function test_override_with_parent_composition(): void
    {
        $user = $this->createUserWithPermissions(['posts.destroy']);

        Gate::policy(PermissionPost::class, OverrideWithParentPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new OverrideWithParentPolicy();

        // User owns the post AND has permission → allowed
        $ownedPost = new PermissionPost();
        $ownedPost->user_id = $user->id;
        $this->assertTrue($policy->delete($user, $ownedPost));

        // User has permission but does NOT own the post → denied
        $otherPost = new PermissionPost();
        $otherPost->user_id = 999;
        $this->assertFalse($policy->delete($user, $otherPost));
    }

    public function test_override_with_parent_denied_by_permission(): void
    {
        // User does NOT have posts.destroy permission
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, OverrideWithParentPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new OverrideWithParentPolicy();

        // User owns the post but lacks permission → denied by parent
        $ownedPost = new PermissionPost();
        $ownedPost->user_id = $user->id;
        $this->assertFalse($policy->delete($user, $ownedPost));
    }

    public function test_full_override_ignores_permissions(): void
    {
        // User has no relevant permissions at all
        $user = $this->createUserWithoutPermissions(3);

        Gate::policy(PermissionPost::class, FullOverridePolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new FullOverridePolicy();

        // viewAny is fully overridden — just checks if user is authenticated
        $this->assertTrue($policy->viewAny($user));

        // Other methods still use ResourcePolicy defaults → denied
        $this->assertFalse($policy->create($user));
    }

    // ------------------------------------------------------------------
    // Auto-resolution of resource slug from config
    // ------------------------------------------------------------------

    public function test_auto_resolves_slug_from_config(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, PermissionPostPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new PermissionPostPolicy();

        // Should auto-resolve 'posts' from config
        $this->assertTrue($policy->viewAny($user));
    }

    // ------------------------------------------------------------------
    // Organization-scoped permissions
    // ------------------------------------------------------------------

    public function test_permissions_are_scoped_to_organization(): void
    {
        $user = \App\Models\User::forceCreate([
            'id' => 10,
            'name' => 'Multi-org User',
            'email' => 'multiorg@example.com',
            'password' => bcrypt('password'),
        ]);

        $org1 = \App\Models\Organization::forceCreate([
            'id' => 10,
            'name' => 'Org A',
            'slug' => 'org-a',
        ]);

        $org2 = \App\Models\Organization::forceCreate([
            'id' => 11,
            'name' => 'Org B',
            'slug' => 'org-b',
        ]);

        $role = \App\Models\Role::firstOrCreate(
            ['slug' => 'test-role'],
            ['name' => 'Test Role']
        );

        // Full access in org1
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org1->id,
            'permissions' => ['*'],
        ]);

        // Read-only in org2
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org2->id,
            'permissions' => ['posts.index', 'posts.show'],
        ]);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['lumina.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        // In org1: can do everything
        request()->merge(['organization' => $org1]);
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->delete($user, new PermissionPost()));

        // In org2: read-only
        request()->merge(['organization' => $org2]);
        $this->assertTrue($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->delete($user, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // ResourcePolicy default hiddenColumns still works
    // ------------------------------------------------------------------

    public function test_hidden_columns_still_works_with_permissions(): void
    {
        $policy = new ResourcePolicy();
        $this->assertEquals([], $policy->hiddenColumns(null));
    }
}
