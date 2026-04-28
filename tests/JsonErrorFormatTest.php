<?php

namespace Spectator\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Middleware;
use Spectator\Spectator;

class JsonErrorFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('Test.v1.yml');
    }

    #[Test]
    public function default_error_format_is_text(): void
    {
        $this->assertEquals('text', config('spectator.error_format', 'text'));
    }

    #[Test]
    public function use_json_errors_sets_json_format(): void
    {
        Spectator::useJsonErrors();

        $this->assertEquals('json', config('spectator.error_format'));
    }

    #[Test]
    public function use_text_errors_sets_text_format(): void
    {
        Spectator::useJsonErrors();
        Spectator::useTextErrors();

        $this->assertEquals('text', config('spectator.error_format'));
    }

    #[Test]
    public function request_validation_error_emits_json_when_configured(): void
    {
        Spectator::useJsonErrors();

        Route::post('/users', fn () => response()->json([], 201))->middleware(Middleware::class);

        // Send request missing required fields — triggers RequestValidationException
        $this->postJson('/users', [])
            ->assertInvalidRequest();

        $message = app('spectator')->requestException->getMessage();

        $decoded = json_decode($message, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertNotEmpty($decoded['errors']);
    }

    #[Test]
    public function response_validation_error_emits_json_when_configured(): void
    {
        Spectator::useJsonErrors();

        Route::get('/users', fn () => response()->json(['id' => 'not-an-integer']))->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertInvalidResponse();

        $message = app('spectator')->responseException->getMessage();

        $decoded = json_decode($message, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertNotEmpty($decoded['errors']);
    }

    #[Test]
    public function request_validation_error_emits_text_by_default(): void
    {
        Route::post('/users', fn () => response()->json([], 201))->middleware(Middleware::class);

        $this->postJson('/users', [])
            ->assertInvalidRequest();

        $message = app('spectator')->requestException->getMessage();

        // Text format contains ANSI escape codes or schema-style markers, not JSON
        $this->assertNull(json_decode($message));
    }

    #[Test]
    public function json_errors_can_be_configured_via_env(): void
    {
        Config::set('spectator.error_format', 'json');

        Route::post('/users', fn () => response()->json([], 201))->middleware(Middleware::class);

        $this->postJson('/users', [])
            ->assertInvalidRequest();

        $message = app('spectator')->requestException->getMessage();

        $decoded = json_decode($message, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('errors', $decoded);
    }
}
