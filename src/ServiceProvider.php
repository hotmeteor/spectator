<?php

namespace Spectator;

use Illuminate\Contracts\Http\Kernel;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->mergeConfig();

        $this->app->singleton(RequestFactory::class);
    }

    public function boot()
    {
        $this->publishConfig();
        $this->registerMiddleware();
        $this->decorateTestResponse();
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
        if (class_exists('\Illuminate\Foundation\Testing\TestResponse')) {
            \Illuminate\Foundation\Testing\TestResponse::mixin(new ResponseMixin());
        } else {
            \Illuminate\Testing\TestResponse::mixin(new ResponseMixin());
        }
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
