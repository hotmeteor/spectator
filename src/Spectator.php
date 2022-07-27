<?php

namespace Spectator;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void using($name) Set the spec file to use.
 * @method static void reset() Reset the name of the spec.
 * @method static string getSpec() Get the spec being used.
 * @method static string getPathPrefix() Get the path prefix being used.
 * @method static RequestFactory setPathPrefix($prefix) Set the path prefix being used.
 * @method static object resolve() Resolve the spec into an object.
 * @method static void skipRequestValidation() Disable request validation.
 * @method bool shouldValidateRequest() Indicate if request should be validated.
 *
 * @see \Spectator\RequestFactory
 */
class Spectator extends Facade
{
    /**
     * @method static void using($name) Set the spec file to use.
     * @method static void reset() Reset the name of the spec.
     * @method static string getSpec() Get the spec being used.
     * @method static string getPathPrefix() Get the path prefix being used.
     * @method static RequestFactory setPathPrefix($prefix) Set the path prefix being used.
     * @method static object resolve() Resolve the spec into an object.
     * @method static void skipRequestValidation() Disable request validation.
     * @method bool shouldValidateRequest() Indicate if request should be validated.
     *
     * @see \Spectator\RequestFactory
     */
    protected static function getFacadeAccessor()
    {
        return RequestFactory::class;
    }
}
