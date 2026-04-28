<?php

namespace Spectator\Tests;

use ErrorException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Middleware;
use Spectator\Spectator;

class AssertionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('Test.v1.json');
    }

    #[Test]
    public function asserts_invalid_path()
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

    #[Test]
    public function fails_asserts_invalid_path()
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

    #[Test]
    public function fails_asserts_invalid_path_without_exception_handling()
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

    #[Test]
    public function exception_points_to_mixin_method()
    {
        $this->withExceptionHandling();

        $this->expectException(ErrorException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('Expected response status code [200] but received 500.');

        Route::get('/users', function () {
            throw new \Exception('Explosion');
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    #[Test]
    public function asserts_path_exists()
    {
        Route::get('/users', fn () => 'ok')->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertPathExists();
    }

    #[Test]
    public function asserts_valid_request(): void
    {
        Route::get('/users', fn () => [
            ['id' => 1, 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest();
    }

    #[Test]
    public function asserts_valid_response(): void
    {
        Route::get('/users', fn () => [
            ['id' => 1, 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidResponse(200);
    }

    #[Test]
    public function asserts_invalid_response(): void
    {
        Route::get('/users', fn () => [
            ['id' => 'not-a-number', 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertInvalidResponse();
    }

    #[Test]
    public function asserts_invalid_response_with_status(): void
    {
        Route::get('/users', fn () => response()->json([
            ['id' => 'not-a-number', 'name' => 'Jim', 'email' => 'test@test.test'],
        ], 200))->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertInvalidResponse(200);
    }

    #[Test]
    public function asserts_errors_contain(): void
    {
        Route::get('/users', fn () => [
            ['id' => 'not-a-number', 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertInvalidResponse()
            ->assertErrorsContain('number');
    }

    #[Test]
    public function asserts_errors_contain_array(): void
    {
        Route::get('/users', fn () => [
            ['id' => 'not-a-number', 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertInvalidResponse()
            ->assertErrorsContain(['number']);
    }

    #[Test]
    public function asserts_path_does_not_exist()
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('Path [GET /invalid] not found in spec.');

        Route::get('/invalid', fn () => 'ok')->middleware(Middleware::class);

        $this->getJson('/invalid')
            ->assertPathExists();
    }
}
