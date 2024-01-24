<?php

namespace Spectator\Tests;

use Illuminate\Support\Facades\Route;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;

class MiddlewareTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);

        Spectator::using('Test.v1.json');
    }

    public function test_only_processes_request_once(): void
    {
        $numberOfTimesProcessed = 0;

        Route::get('/users', static function () use (&$numberOfTimesProcessed) {
            $numberOfTimesProcessed++;

            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users');

        static::assertEquals(1, $numberOfTimesProcessed);
    }
}
