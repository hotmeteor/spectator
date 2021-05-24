<?php

namespace Spectator\Tests;

use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public $testing = false;

    protected function getEnvironmentSetUp($app)
    {
        Config::set('spectator.suppress_errors', true);
    }

    protected function getPackageProviders($app)
    {
        return ['Spectator\SpectatorServiceProvider'];
    }
}
