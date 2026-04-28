<?php

namespace Spectator;

use Illuminate\Support\Facades\Facade;

/**
 * @method static RequestFactory using(string|null $name) Set the spec file to use.
 * @method static void reset() Reset the name and prefix of the spec.
 * @method static string getSpec() Get the spec being used.
 * @method static string getPathPrefix() Get the path prefix being used.
 * @method static RequestFactory setPathPrefix(string|null $prefix) Set the path prefix being used.
 * @method static RequestFactory withPathPrefix(string|null $prefix) Fluent alias for setPathPrefix.
 * @method static RequestFactory useJsonErrors() Emit validation errors as machine-readable JSON.
 * @method static RequestFactory useTextErrors() Emit validation errors as human-readable text (default).
 * @method static \stdClass resolve() Resolve the spec into an object.
 * @method bool shouldValidateRequest() Indicate if request should be validated.
 *
 * @see RequestFactory
 */
class Spectator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RequestFactory::class;
    }
}
