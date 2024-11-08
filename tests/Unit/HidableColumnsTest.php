<?php

namespace Lumina\LaravelApi\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Lumina\LaravelApi\Contracts\HasHiddenColumns;
use Lumina\LaravelApi\Policies\ResourcePolicy;
use Lumina\LaravelApi\Tests\TestCase;
use Lumina\LaravelApi\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class HidablePost extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

class HidablePostWithAdditional extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
    protected $additionalHiddenColumns = ['internal_notes'];
}

class HidablePostWithNoPolicy extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

class HidablePostWithInterfacePolicy extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

/** Model with a computed (virtual) attribute via $appends + Accessor. */
class HidablePostWithComputed extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
    protected $appends = ['rank', 'summary'];

    protected function rank(): Attribute
    {
        return Attribute::make(
            get: fn () => 42
        );
    }

    protected function summary(): Attribute
    {
        return Attribute::make(
            get: fn () => 'Summary of: ' . ($this->title ?? '')
        );
    }
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class HidablePostPolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['cost_price', 'margin', 'internal_notes'];
        }

        // Simulate role check: user with id=1 is "admin"
        if ($user->getAuthIdentifier() === 1) {
            return [];
        }

        return ['cost_price', 'margin'];
    }
}

class HidablePostWithAdditionalPolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['cost_price'];
        }

        return [];
    }
}

/**
 * A policy that does NOT extend ResourcePolicy and does NOT implement HasHiddenColumns.
 */
class PlainPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }
}

/**
 * A policy that implements HasHiddenColumns directly without extending ResourcePolicy.
 */
class InterfaceOnlyPolicy implements HasHiddenColumns
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        return ['margin'];
    }
}

/**
 * Policy that hides computed attributes for non-admin users.
 */
class ComputedAttributePolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['rank', 'summary'];
        }

        if ($user->getAuthIdentifier() === 1) {
            return []; // admin sees everything
        }

        return ['rank']; // regular user: hide rank, show summary
    }
}

/**
 * Spy policy that counts how many times hiddenColumns is called.
 */
class SpyPolicy extends ResourcePolicy
{
    public static int $callCount = 0;

    public function hiddenColumns(?Authenticatable $user): array
    {
        static::$callCount++;
        return ['cost_price'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class HidableColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run users migration for actingAs support
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('hidable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('margin', 10, 2)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        // Clear static cache between tests to avoid cross-test contamination
        HidablePost::clearHiddenColumnsCache();
        HidablePostWithAdditional::clearHiddenColumnsCache();
        HidablePostWithNoPolicy::clearHiddenColumnsCache();
        HidablePostWithInterfacePolicy::clearHiddenColumnsCache();
        HidablePostWithComputed::clearHiddenColumnsCache();
        SpyPolicy::$callCount = 0;

        parent::tearDown();
    }

    /**
     * Register a policy for a model in the Gate.
     */
    protected function registerPolicy(string $modelClass, string $policyClass): void
    {
        Gate::policy($modelClass, $policyClass);
    }

    /**
     * Create a simple test user.
     */
    protected function createUser(int $id = 1, string $name = 'Test User'): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'id' => $id,
            'name' => $name,
            'email' => "user{$id}@example.com",
            'password' => bcrypt('password'),
        ]);
    }

    // ------------------------------------------------------------------
    // Base behavior tests
    // ------------------------------------------------------------------

    public function test_base_hidden_columns_are_always_applied(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content here',
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
        $this->assertArrayNotHasKey('deleted_at', $array);
    }

    public function test_additional_hidden_columns_are_applied(): void
    {
        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content here',
            'internal_notes' => 'Secret notes',
        ]);

        $array = $post->toArray();

        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
    }

    // ------------------------------------------------------------------
    // Policy-based hiding tests
    // ------------------------------------------------------------------

    public function test_policy_hidden_columns_applied_for_guest_user(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // No authenticated user — guest
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
    }

    public function test_policy_hidden_columns_applied_for_regular_user(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $user = $this->createUser(2, 'Regular User');
        $this->actingAs($user);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Regular user (id=2) should not see cost_price and margin
        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        // But should see internal_notes (policy only hides cost_price, margin for auth users)
        $this->assertArrayHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hidden_columns_admin_sees_everything(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $admin = $this->createUser(1, 'Admin User');
        $this->actingAs($admin);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Admin (id=1) should see cost_price, margin, and internal_notes
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Additive behavior tests
    // ------------------------------------------------------------------

    public function test_policy_columns_are_additive_with_additional_hidden_columns(): void
    {
        $this->registerPolicy(HidablePostWithAdditional::class, HidablePostWithAdditionalPolicy::class);

        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest: policy hides cost_price, $additionalHiddenColumns hides internal_notes
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_returning_empty_array_does_not_unhide_base_columns(): void
    {
        $this->registerPolicy(HidablePostWithAdditional::class, HidablePostWithAdditionalPolicy::class);

        $user = $this->createUser(1, 'Admin');
        $this->actingAs($user);

        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Policy returns [] for auth user, but $additionalHiddenColumns still hides internal_notes
        $this->assertArrayNotHasKey('internal_notes', $array);
        // Base columns are still hidden
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('created_at', $array);
    }

    // ------------------------------------------------------------------
    // Fallback behavior tests
    // ------------------------------------------------------------------

    public function test_model_with_no_policy_falls_back_to_static_hidden(): void
    {
        // Don't register any policy for HidablePostWithNoPolicy
        $post = HidablePostWithNoPolicy::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
        ]);

        $array = $post->toArray();

        // No policy, so only base hidden columns are applied
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('title', $array);
        // Base columns still hidden
        $this->assertArrayNotHasKey('created_at', $array);
    }

    public function test_model_with_plain_policy_not_implementing_interface_falls_back(): void
    {
        $this->registerPolicy(HidablePostWithNoPolicy::class, PlainPolicy::class);

        $post = HidablePostWithNoPolicy::forceCreate([
            'title' => 'Test Post',
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        // PlainPolicy doesn't implement HasHiddenColumns, so no extra hiding
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Interface-only policy tests
    // ------------------------------------------------------------------

    public function test_policy_implementing_interface_directly_works(): void
    {
        $this->registerPolicy(HidablePostWithInterfacePolicy::class, InterfaceOnlyPolicy::class);

        $post = HidablePostWithInterfacePolicy::forceCreate([
            'title' => 'Test Post',
            'margin' => 20.00,
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        // InterfaceOnlyPolicy hides 'margin'
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Cache tests
    // ------------------------------------------------------------------

    public function test_cache_prevents_multiple_policy_resolutions(): void
    {
        Gate::policy(HidablePost::class, SpyPolicy::class);

        // Create multiple posts
        HidablePost::forceCreate(['title' => 'Post 1', 'cost_price' => 10]);
        HidablePost::forceCreate(['title' => 'Post 2', 'cost_price' => 20]);
        HidablePost::forceCreate(['title' => 'Post 3', 'cost_price' => 30]);

        $posts = HidablePost::all();

        // Serialize all posts (triggers getHidden on each)
        $posts->toArray();

        // hiddenColumns() should have been called only once due to caching
        $this->assertEquals(1, SpyPolicy::$callCount);
    }

    public function test_clear_hidden_columns_cache_resets_cache(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'cost_price' => 100.00,
        ]);

        // Guest — cost_price is hidden
        $array = $post->toArray();
        $this->assertArrayNotHasKey('cost_price', $array);

        // Now authenticate as admin
        $admin = $this->createUser(1, 'Admin');
        $this->actingAs($admin);

        // Clear cache to pick up the new user context
        HidablePost::clearHiddenColumnsCache();

        // Re-fetch to get a fresh model instance
        $post = HidablePost::find($post->id);
        $array = $post->toArray();

        // Admin sees everything
        $this->assertArrayHasKey('cost_price', $array);
    }

    // ------------------------------------------------------------------
    // ResourcePolicy default behavior tests
    // ------------------------------------------------------------------

    public function test_resource_policy_default_returns_empty_array(): void
    {
        $policy = new ResourcePolicy();
        $result = $policy->hiddenColumns(null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_resource_policy_implements_has_hidden_columns(): void
    {
        $policy = new ResourcePolicy();

        $this->assertInstanceOf(HasHiddenColumns::class, $policy);
    }

    // ------------------------------------------------------------------
    // hideAdditionalColumns method tests
    // ------------------------------------------------------------------

    public function test_hide_additional_columns_method_still_works(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
        ]);

        $post->hideAdditionalColumns(['cost_price']);
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Computed (virtual) attribute tests
    // ------------------------------------------------------------------

    public function test_computed_attributes_are_included_in_response_when_appended(): void
    {
        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        $this->assertArrayHasKey('rank', $array);
        $this->assertSame(42, $array['rank']);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hides_computed_attributes_for_guest(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        // No auth user (guest) — policy hides both rank and summary
        $array = $post->toArray();

        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayNotHasKey('summary', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hides_computed_attributes_for_regular_user(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $user = $this->createUser(2, 'Regular User');
        $this->actingAs($user);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        // Regular user: rank hidden, summary visible
        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_admin_sees_all_computed_attributes(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $admin = $this->createUser(1, 'Admin');
        $this->actingAs($admin);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        // Admin sees rank and summary
        $this->assertArrayHasKey('rank', $array);
        $this->assertSame(42, $array['rank']);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }
}
