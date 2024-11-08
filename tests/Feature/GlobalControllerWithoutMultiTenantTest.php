<?php

namespace Lumina\LaravelApi\Tests\Feature;

use Lumina\LaravelApi\Tests\TestCase;

class GlobalControllerWithoutMultiTenantTest extends TestCase
{
    public function test_global_controller_works_without_multi_tenant()
    {
        // Verify multi-tenant is disabled by default
        $this->assertFalse(config('lumina.multi_tenant.enabled'));
    }

    public function test_config_is_properly_set()
    {
        $config = config('lumina');
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('models', $config);
        $this->assertArrayHasKey('public', $config);
        $this->assertArrayHasKey('multi_tenant', $config);
    }
}
