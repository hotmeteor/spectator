<?php

namespace Spectator;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void using($name): void
 * @method static void reset(): void
 * @method static string getSpec(): string
 * @method static string getPathPrefix(): string
 * @method static RequestFactory setPathPrefix($prefix): RequestFactory
 * @method static object resolve(): SpecObjectInterface
 *
 * @see \Spectator\RequestFactory
 */
class Spectator extends Facade
{
    /**
     * @method static void using($name): void
     * @method static void reset(): void
     * @method static string getSpec(): string|null
     * @method static string getPathPrefix(): string
     * @method static RequestFactory setPathPrefix($prefix): RequestFactory
     * @method static object resolve(): SpecObjectInterface
     *
     * @see \Spectator\RequestFactory
     */
    protected static function getFacadeAccessor()
    {
        return RequestFactory::class;
    }
}
