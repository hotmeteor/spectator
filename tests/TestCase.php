<?php

namespace Spectator\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return ['Spectator\SpectatorServiceProvider'];
    }
}
