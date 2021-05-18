<?php

namespace Spectator\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;
use stdClass;

class RequestValidatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);
    }

    public function test_cannot_resolve_prefixed_path()
    {
        Spectator::using('Versioned.v1.json');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /v1/users] not found in spec.');
    }

    public function test_resolves_prefixed_path_from_config()
    {
        Spectator::using('Versioned.v1.json');
        Config::set('spectator.path_prefix', 'v1');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_resolves_prefixed_path_from_inline_setting()
    {
        Spectator::setPathPrefix('v1')->using('Versioned.v1.json');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_resolve_route_model_binding()
    {
        Spectator::using('Test.v1.json');

        Route::get('/users/{user}', function () {
            return [
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users/1')
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_resolve_route_model_explicit_binding()
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/'.Str::uuid()->toString())
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_cannot_resolve_route_model_explicit_binding_with_invalid_format()
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/invalid')
            ->assertInvalidRequest()
            ->assertValidResponse(400);
    }

    public function test_resolve_route_model_binding_with_multiple_parameters()
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}/comments/{comment}', function () {
            return [
                'id' => 1,
                'message' => 'My Comment',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/'.Str::uuid()->toString().'/comments/1')
            ->assertValidRequest()
            ->assertValidResponse();
    }
}

class TestUser extends Model
{
}
