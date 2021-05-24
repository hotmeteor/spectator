<?php

namespace Spectator\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;

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

    /**
     * @dataProvider nullableProvider
     */
    public function test_handle_nullables(
        $version,
        $state,
        $is_valid
    ) {
//        Spectator::using("Nullable.{$version}.json");

        Route::post('/users')->middleware(Middleware::class);

        $payload = [
            'name' => 'Adam Campbell',
            'email' => 'adam@hotmeteor.com',
        ];

        if ($state === self::NULLABLE_MISSING) {
            // Well... it's missing.
        }

        if ($state === self::NULLABLE_EMPTY_STRING) {
            $payload['nickname'] = '';
        }

        if ($state === self::NULLABLE_VALID) {
            $payload['nickname'] = 'hotmeteor';
        }

        if ($state === self::NULLABLE_NULL) {
            $payload['nickname'] = null;
        }

        if ($is_valid) {
            $this->postJson('/users', $payload)
                ->assertValidRequest()
                ->assertValidResponse();
        } else {
            $this->postJson('/users', $payload)
                ->assertInvalidRequest()
                ->assertValidResponse();
        }
    }

    public function nullableProvider()
    {
        $validResponse = true;
        $invalidResponse = false;

        $v30 = '3.0';
        $v31 = '3.1';

        return [
            // OA 3.0.0
            '3.0, missing' => [
                $v30,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.0, empty' => [
                $v30,
                self::NULLABLE_EMPTY_STRING,
                $validResponse,
            ],

            '3.0, null' => [
                $v30,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.0, valid' => [
                $v30,
                self::NULLABLE_VALID,
                $validResponse,
            ],

            // OA 3.1.0
            '3.1, missing' => [
                $v31,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.1, empty' => [
                $v31,
                self::NULLABLE_EMPTY_STRING,
                $validResponse,
            ],

            '3.1, null' => [
                $v31,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.1, valid' => [
                $v31,
                self::NULLABLE_VALID,
                $validResponse,
            ],

        ];
    }
}

class TestUser extends Model
{
}
