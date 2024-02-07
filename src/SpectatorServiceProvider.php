<?php

namespace Spectator;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;

class SpectatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (App::runningInConsole()) {
            $this->publishConfig();
            $this->registerMiddleware();
            $this->decorateTestResponse();
        }
    }

    public function register(): void
    {
        $this->mergeConfig();

        $this->app->singleton(RequestFactory::class);
        $this->app->alias(RequestFactory::class, 'spectator');
    }

    protected function mergeConfig(): void
    {
        $configPath = __DIR__.'/../config/spectator.php';

        $this->mergeConfigFrom($configPath, 'spectator');
    }

    protected function registerMiddleware(): void
    {
        $groups = config('spectator.middleware_groups');

        foreach ($groups as $group) {
            $this->app[Kernel::class]->prependMiddlewareToGroup($group, Middleware::class);
        }
    }

    protected function decorateTestResponse(): void
    {
        TestResponse::mixin(new Assertions());
    }

    protected function getConfigPath(): string
    {
        return config_path('spectator.php');
    }

    protected function publishConfig(): void
    {
        $configPath = __DIR__.'/../config/spectator.php';

        $this->publishes([$configPath => $this->getConfigPath()], 'config');
    }
}
