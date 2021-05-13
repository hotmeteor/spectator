<?php

namespace Spectator\Tests;

use Illuminate\Support\Facades\Route;
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

    public function testAssertsInvalidPath()
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

    public function testExceptionPointsToMixinMethod()
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
}
