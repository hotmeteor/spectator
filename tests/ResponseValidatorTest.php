<?php

namespace Spectator\Tests;

use Spectator\Spectator;
use Spectator\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Config;
use Spectator\SpectatorServiceProvider;

class ResponseValidatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);

        Spectator::using('Test.v1.json');
    }

    public function test_validates_valid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_validates_invalid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('get-users json response field 0.id does not match the spec: [ type: {"expected":"number","used":"string"} ]');

        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'email' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('get-users json response field 0.email does not match the spec: [ format: {"type":"string","format":"email"} ]');
    }

    public function test_fallback_to_request_uri_if_operationId_not_given()
    {
        Spectator::using('Test.v1.json');

        Route::get('/path-without-operationId', function () {
            return [
                'int' => 'not an int',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/path-without-operationId')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('path-without-operationId json response field int does not match the spec: [ type: {"expected":"integer","used":"string"} ]');
    }

    public function test_cannot_locate_path_without_path_prefix()
    {
        Spectator::using('Test.v2.json');

        Route::get('/api/v2/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/api/v2/users')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /api/v2/users] not found in spec.');

        Config::set('spectator.path_prefix', '/api/v2/');

        $this->getJson('/api/v2/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_handle_nullable_in_oa3dot0()
    {
        Spectator::using('Nullable.3.0.json');

        Route::get('/api/v1/users/1', function () {
            return [
                [
                    'first_name' => 'Joe',
                    'last_name' => 'Bloggs',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/api/v1/users/1')
            ->assertValidRequest()
            ->assertValidResponse();
    }
}
