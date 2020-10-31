<?php

namespace Spectator\Tests;

use Spectator\Spectator;
use Spectator\Middleware;
use Spectator\ServiceProvider;
use Illuminate\Support\Facades\Route;

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
            return response()->json([
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ]);
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_validates_invalid_json_response()
    {
        Route::get('/users', function () {
            return response()->json([
                'id' => 'invalid',
                'invalid' => null,
            ]);
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('get-users does not match the spec: [ type: {"expected":"integer","used":"string"} ]');
    }
}
