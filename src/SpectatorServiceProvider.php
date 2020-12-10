<?php

namespace Spectator;

use LogicException;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\TestResponse;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Testing\TestResponse as LegacyTestResponse;

class SpectatorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if (App::runningInConsole()) {
            $this->publishConfig();
            $this->registerMiddleware();
            $this->decorateTestResponse();
        }
    }

    public function register()
    {
        $this->mergeConfig();

        $this->app->singleton(RequestFactory::class);
    }

    protected function mergeConfig()
    {
        $configPath = __DIR__.'/../config/spectator.php';

        $this->mergeConfigFrom($configPath, 'spectator');
    }

    protected function registerMiddleware()
    {
        $this->app[Kernel::class]->appendMiddlewareToGroup('api', Middleware::class);
    }

    protected function decorateTestResponse()
    {
        // Laravel >= 7.0
        if (class_exists(TestResponse::class)) {
            TestResponse::mixin(new Assertions());

            return;
        }

        // Laravel <= 6.0
        if (class_exists(LegacyTestResponse::class)) {
            LegacyTestResponse::mixin(new Assertions());

            return;
        }

        throw new LogicException('Could not detect TestResponse class.');
    }

    protected function getConfigPath()
    {
        return config_path('spectator.php');
    }

    protected function publishConfig()
    {
        $configPath = __DIR__.'/../config/spectator.php';

        $this->publishes([$configPath => $this->getConfigPath()], 'config');
    }
}
