<?php

namespace Spectator\Tests;

use Spectator\Spectator;
use Spectator\Middleware;
use Spectator\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Config;

class ResponseValidatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(ServiceProvider::class);

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
            ->assertValidationMessage('/path-without-operationId json response field int does not match the spec: [ type: {"expected":"integer","used":"string"} ]');
    }

    public function test_cannot_locate_path_without_path_prefix()
    {
        Spectator::using('Test.v2.json');

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
            ->assertValidResponse(422)
            ->assertValidationMessage('Path [GET /users] not found in spec.');

        Config::set('spectator.path_prefix', 'v2');

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }
}
