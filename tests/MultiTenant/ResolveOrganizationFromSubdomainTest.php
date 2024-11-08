<?php

namespace Lumina\LaravelApi\Tests\MultiTenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Lumina\LaravelApi\Http\Middleware\ResolveOrganizationFromSubdomain;
use Lumina\LaravelApi\Tests\TestCase;

class ResolveOrganizationFromSubdomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable multi-tenant
        config(['global-controller.multi_tenant.enabled' => true]);
        config(['global-controller.multi_tenant.use_subdomain' => true]);
        config(['global-controller.multi_tenant.organization_identifier_column' => 'slug']);

        // Create organizations table
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function test_middleware_passes_through_for_main_domain()
    {
        $middleware = new ResolveOrganizationFromSubdomain();
        $request = Request::create('http://localhost/api/users', 'GET');
        
        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_resolves_organization_by_subdomain()
    {
        // Create organization using model
        $organization = \App\Models\Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $middleware = new ResolveOrganizationFromSubdomain();
        $request = Request::create('http://test-org.example.com/api/users', 'GET');
        
        $response = $middleware->handle($request, function ($req) {
            $org = $req->attributes->get('organization');
            return response()->json([
                'success' => true,
                'organization_id' => $org->id,
                'organization_slug' => $org->slug,
            ]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals($organization->id, $data['organization_id']);
        $this->assertEquals('test-org', $data['organization_slug']);
    }

    public function test_middleware_returns_404_when_organization_not_found()
    {
        $middleware = new ResolveOrganizationFromSubdomain();
        $request = Request::create('http://nonexistent.example.com/api/users', 'GET');

        try {
            $middleware->handle($request, function ($req) {
                return response()->json(['success' => true]);
            });
            $this->fail('Expected 404 exception was not thrown');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(404, $e->getStatusCode());
        }
    }
}
