<?php

namespace Spectator\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Middleware;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function middleware_is_registered()
    {
        $kernel = App::make(Kernel::class);

        $this->assertTrue(in_array(Middleware::class, $kernel->getMiddlewareGroups()['api'], true));
    }
}
