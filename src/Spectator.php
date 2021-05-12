<?php

namespace Spectator;

use Illuminate\Support\Facades\Facade;

/**
 * Class Spectator
 *
 * @method static void using($name): void
 * @method static void reset(): void
 * @method static void getSpec(): string|null
 * @method static void resolve(): SpecObjectInterface
 *
 * @package Spectator
 */
class Spectator extends Facade
{
    /**
     * @method static void using($name): void
     * @method static void reset(): void
     * @method static void getSpec(): string|null
     * @method static void resolve(): SpecObjectInterface
     *
     * @see \Spectator\RequestFactory
     */
    protected static function getFacadeAccessor()
    {
        return RequestFactory::class;
    }
}
