<?php

namespace Spectator\Tests;

use Spectator\Middleware;
use Illuminate\Support\Facades\App;
use Illuminate\Contracts\Http\Kernel;

class ServiceProviderTest extends TestCase
{
    public function test_middleware_is_registered()
    {
        $kernel = App::make(Kernel::class);

        $this->assertTrue(in_array(Middleware::class, $kernel->getMiddlewareGroups()['api'], true));
    }
}
