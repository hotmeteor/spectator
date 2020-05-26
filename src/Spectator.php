<?php

namespace Spectator;

use Illuminate\Support\Facades\Facade;

class Spectator extends Facade
{
    /**
     * @method static void using($name): void
     * @method static void reset(): void
     * @method static void getSpec(): string|null
     *
     * @see \Spectator\RequestFactory
     */
    protected static function getFacadeAccessor()
    {
        return RequestFactory::class;
    }
}
