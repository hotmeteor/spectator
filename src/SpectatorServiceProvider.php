<?php

namespace Spectator;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;

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
        $this->app->alias(RequestFactory::class, 'spectator');
    }

    protected function mergeConfig()
    {
        $configPath = __DIR__.'/../config/spectator.php';

        $this->mergeConfigFrom($configPath, 'spectator');
    }

    protected function registerMiddleware()
    {
        $groups = config('spectator.middleware_groups');

        foreach ($groups as $group) {
            $this->app[Kernel::class]->prependMiddlewareToGroup($group, Middleware::class);
        }
    }

    protected function decorateTestResponse()
    {
        TestResponse::mixin(new Assertions());
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
