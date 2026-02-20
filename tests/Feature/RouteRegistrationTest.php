<?php

namespace Lumina\LaravelApi\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Lumina\LaravelApi\Tests\TestCase;
use Lumina\LaravelApi\Traits\HasValidation;
use Lumina\LaravelApi\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class RoutablePost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];
}

class RoutablePostWithMiddleware extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];

    public static array $middleware = ['throttle:60,1'];

    public static array $middlewareActions = [
        'store' => ['verified'],
        'update' => ['verified'],
    ];
}

class RoutablePostWithExcept extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];

    public static array $exceptActions = ['destroy', 'update'];
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RouteRegistrationTest extends TestCase
{
    /**
     * Helper: register models in config and load the route file.
     */
    protected function registerModelsAndLoadRoutes(array $models, array $public = [], array $multiTenant = []): void
    {
        $defaultMultiTenant = [
            'enabled' => false,
            'use_subdomain' => false,
            'organization_identifier_column' => 'id',
            'middleware' => null,
        ];

        config([
            'lumina.models' => $models,
            'lumina.public' => $public,
            'lumina.multi_tenant' => array_merge($defaultMultiTenant, $multiTenant),
        ]);

        // Load routes within the api prefix to match real app behavior
        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    /**
     * Helper: get all registered route names.
     */
    protected function getRouteNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Helper: get route by name (iterates collection to avoid stale name lookup cache).
     */
    protected function getRouteByName(string $name): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Basic route registration
    // ------------------------------------------------------------------

    public function test_registers_all_crud_routes_for_model(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $names = $this->getRouteNames();

        $this->assertContains('posts.index', $names);
        $this->assertContains('posts.store', $names);
        $this->assertContains('posts.show', $names);
        $this->assertContains('posts.update', $names);
        $this->assertContains('posts.destroy', $names);
    }

    public function test_registers_routes_for_multiple_models(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
            'comments' => RoutablePostWithMiddleware::class,
        ]);

        $names = $this->getRouteNames();

        $this->assertContains('posts.index', $names);
        $this->assertContains('posts.store', $names);
        $this->assertContains('comments.index', $names);
        $this->assertContains('comments.store', $names);
    }

    public function test_routes_have_correct_http_methods(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $this->assertEquals(['GET', 'HEAD'], $this->getRouteByName('posts.index')->methods());
        $this->assertEquals(['POST'], $this->getRouteByName('posts.store')->methods());
        $this->assertEquals(['GET', 'HEAD'], $this->getRouteByName('posts.show')->methods());
        $this->assertEquals(['PUT'], $this->getRouteByName('posts.update')->methods());
        $this->assertEquals(['DELETE'], $this->getRouteByName('posts.destroy')->methods());
    }

    public function test_routes_have_correct_uri_without_multi_tenant(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $this->assertEquals('api/posts', $this->getRouteByName('posts.index')->uri());
        $this->assertEquals('api/posts', $this->getRouteByName('posts.store')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('posts.show')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('posts.update')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('posts.destroy')->uri());
    }

    // ------------------------------------------------------------------
    // Multi-tenant route prefix
    // ------------------------------------------------------------------

    public function test_routes_have_organization_prefix_with_multi_tenant_route_prefix(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [],
            ['enabled' => true, 'use_subdomain' => false]
        );

        $this->assertEquals('api/{organization}/posts', $this->getRouteByName('posts.index')->uri());
        $this->assertEquals('api/{organization}/posts/{id}', $this->getRouteByName('posts.show')->uri());
    }

    public function test_routes_have_no_organization_prefix_with_subdomain_multi_tenant(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [],
            ['enabled' => true, 'use_subdomain' => true]
        );

        // Subdomain mode: no {organization} in the URL
        $this->assertEquals('api/posts', $this->getRouteByName('posts.index')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('posts.show')->uri());
    }

    // ------------------------------------------------------------------
    // Middleware
    // ------------------------------------------------------------------

    public function test_auth_middleware_applied_to_non_public_models(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [] // not public
        );

        $middleware = $this->getRouteByName('posts.index')->middleware();
        $this->assertContains('auth:sanctum', $middleware);
    }

    public function test_auth_middleware_not_applied_to_public_models(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            ['posts'] // public
        );

        $middleware = $this->getRouteByName('posts.index')->middleware();
        $this->assertNotContains('auth:sanctum', $middleware);
    }

    public function test_model_level_middleware_applied_to_all_actions(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithMiddleware::class,
        ]);

        // $middleware = ['throttle:60,1'] on the model
        $this->assertContains('throttle:60,1', $this->getRouteByName('posts.index')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('posts.store')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('posts.show')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('posts.update')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('posts.destroy')->middleware());
    }

    public function test_per_action_middleware_applied_only_to_specified_actions(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithMiddleware::class,
        ]);

        // $middlewareActions = ['store' => ['verified'], 'update' => ['verified']]
        $this->assertContains('verified', $this->getRouteByName('posts.store')->middleware());
        $this->assertContains('verified', $this->getRouteByName('posts.update')->middleware());

        // Not applied to other actions
        $this->assertNotContains('verified', $this->getRouteByName('posts.index')->middleware());
        $this->assertNotContains('verified', $this->getRouteByName('posts.show')->middleware());
        $this->assertNotContains('verified', $this->getRouteByName('posts.destroy')->middleware());
    }

    public function test_multi_tenant_middleware_applied_when_enabled(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [],
            [
                'enabled' => true,
                'use_subdomain' => false,
                'middleware' => 'App\Http\Middleware\ResolveOrganizationFromRoute',
            ]
        );

        $middleware = $this->getRouteByName('posts.index')->middleware();
        $this->assertContains('App\Http\Middleware\ResolveOrganizationFromRoute', $middleware);
    }

    // ------------------------------------------------------------------
    // Except actions
    // ------------------------------------------------------------------

    public function test_excepted_actions_are_not_registered(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithExcept::class,
        ]);

        $names = $this->getRouteNames();

        // destroy and update are excepted
        $this->assertNotContains('posts.destroy', $names);
        $this->assertNotContains('posts.update', $names);

        // Other actions are still registered
        $this->assertContains('posts.index', $names);
        $this->assertContains('posts.store', $names);
        $this->assertContains('posts.show', $names);
    }

    // ------------------------------------------------------------------
    // Route defaults
    // ------------------------------------------------------------------

    public function test_model_slug_passed_via_route_defaults(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $route = $this->getRouteByName('posts.index');
        $defaults = $route->defaults;

        $this->assertArrayHasKey('model', $defaults);
        $this->assertEquals('posts', $defaults['model']);
    }

    // ------------------------------------------------------------------
    // Empty config
    // ------------------------------------------------------------------

    public function test_no_crud_routes_registered_when_no_models_configured(): void
    {
        $this->registerModelsAndLoadRoutes([]);

        $names = $this->getRouteNames();

        // Only auth routes should exist, no model CRUD routes
        $this->assertNotContains('posts.index', $names);
    }
}
