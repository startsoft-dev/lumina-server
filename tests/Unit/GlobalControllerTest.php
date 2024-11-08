<?php

namespace Lumina\LaravelApi\Tests\Unit;

use Lumina\LaravelApi\Tests\TestCase;
use Lumina\LaravelApi\Controllers\GlobalController;

class GlobalControllerTest extends TestCase
{
    public function test_global_controller_can_be_instantiated()
    {
        $controller = new GlobalController();
        $this->assertInstanceOf(GlobalController::class, $controller);
    }
}
