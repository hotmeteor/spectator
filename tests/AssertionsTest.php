<?php

namespace Spectator\Tests;

use ErrorException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;

class AssertionsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);

        Spectator::using('Test.v1.json');
    }

    public function test_asserts_invalid_path()
    {
        Route::get('/invalid', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/invalid')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /invalid] not found in spec.');
    }

    public function test_fails_asserts_invalid_path()
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Path [GET /invalid] not found in spec.');

        Route::get('/invalid', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/invalid')
            ->assertValidRequest();
    }

    public function test_fails_asserts_invalid_path_without_exception_handling()
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Path [GET /invalid] not found in spec.');

        Route::get('/invalid', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->withoutExceptionHandling();

        $this->getJson('/invalid')
            ->assertValidRequest();
    }

    public function test_exception_points_to_mixin_method()
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('No response object matching returned status code [500].');

        Route::get('/users', function () {
            throw new \Exception('Explosion');
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_request_assertion_does_not_format_laravel_validation_response_errors_when_errors_are_not_suppressed(): void
    {
        Config::set('spectator.suppress_errors', false);

        Route::post('/users', function () {
            throw ValidationException::withMessages([
                'email' => [
                    'The provided email address is already taken.',
                ],
            ]);
        })->middleware(Middleware::class);

        $response = $this->postJson('/users', [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
        ]);

        $response
            ->assertValidRequest()
            ->assertValidResponse(422);
    }

    public function test_asserts_path_exists()
    {
        Route::get('/users')->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertPathExists();
    }

    public function test_asserts_path_does_not_exist()
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('Path [GET /invalid] not found in spec.');

        Route::get('/invalid')->middleware(Middleware::class);

        $this->getJson('/invalid')
            ->assertPathExists();
    }
}
