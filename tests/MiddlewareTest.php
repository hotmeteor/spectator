<?php

namespace Spectator\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Middleware;
use Spectator\Spectator;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('Test.v1.json');
    }

    #[Test]
    public function only_processes_request_once(): void
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

    #[Test]
    public function is_noop_when_no_spec_set(): void
    {
        Spectator::using(null);

        $passed = false;

        Route::get('/anything', static function () use (&$passed) {
            $passed = true;

            return response()->noContent();
        })->middleware(Middleware::class);

        $this->getJson('/anything')->assertNoContent();

        $this->assertTrue($passed, 'The route handler was not called when no spec was set.');
        $this->assertNull(app('spectator')->requestException);
        $this->assertNull(app('spectator')->responseException);
    }

    #[Test]
    public function captures_invalid_path_exception(): void
    {
        Route::get('/not-in-spec', fn () => 'ok')->middleware(Middleware::class);

        $this->getJson('/not-in-spec')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /not-in-spec] not found in spec.');

        $this->assertInstanceOf(InvalidPathException::class, app('spectator')->requestException);
    }

    #[Test]
    public function path_prefix_is_stripped_for_matching(): void
    {
        Spectator::using('Versioned.v1.json');

        app('spectator')->setPathPrefix('v1');

        Route::get('/v1/users', fn () => [
            ['id' => 1, 'name' => 'Jim', 'email' => 'test@test.test'],
        ])->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertValidRequest()
            ->assertValidResponse();
    }
}
