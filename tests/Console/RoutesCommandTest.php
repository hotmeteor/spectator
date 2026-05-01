<?php

namespace Spectator\Tests\Console;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Middleware;
use Spectator\Tests\TestCase;

class RoutesCommandTest extends TestCase
{
    #[Test]
    public function test_shows_matched_routes(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::post('/users', fn () => [])->name('users.store');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('matched');
    }

    #[Test]
    public function test_shows_unimplemented_when_route_missing(): void
    {
        // Deliberately register NO routes — all spec operations are unimplemented
        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('unimplemented');
    }

    #[Test]
    public function test_shows_undocumented_when_route_not_in_spec(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::get('/not-in-spec', fn () => [])->name('extra');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('undocumented');
    }

    #[Test]
    public function test_json_format_contains_spec_operations(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"spec_operations"')
            ->expectsOutputToContain('"spec": "Test.v1.yml"');
    }

    #[Test]
    public function test_json_format_contains_undocumented(): void
    {
        Route::get('/not-in-spec', fn () => [])->name('extra');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"undocumented_routes"');
    }

    #[Test]
    public function test_json_format_matched_status(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::post('/users', fn () => [])->name('users.store');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"');
    }

    #[Test]
    public function test_json_format_unimplemented_status(): void
    {
        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "unimplemented"');
    }

    #[Test]
    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:routes')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    #[Test]
    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:routes', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1);
    }

    #[Test]
    public function test_summary_line_shown(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('matched,');
    }

    #[Test]
    public function test_no_filter_keeps_existing_behavior(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"')
            ->expectsOutputToContain('"path": "/admin/things"');
    }

    #[Test]
    public function test_prefix_filter_excludes_routes_outside_prefix(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');
        Route::get('/internal/health', fn () => [])->name('internal.health');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'users',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"')
            ->expectsOutputToContain('"path": "/users"')
            ->doesntExpectOutputToContain('"path": "/admin/things"')
            ->doesntExpectOutputToContain('"path": "/internal/health"');
    }

    #[Test]
    public function test_prefix_filter_with_no_matches_yields_empty_undocumented(): void
    {
        Route::get('/admin/things', fn () => [])->name('admin.things');
        Route::get('/internal/health', fn () => [])->name('internal.health');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'api/v2',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('"path": "/admin/things"')
            ->doesntExpectOutputToContain('"path": "/internal/health"');
    }

    #[Test]
    public function test_prefix_filter_normalises_leading_and_trailing_slashes(): void
    {
        Route::get('/api/v2/things', fn () => [])->name('things.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => '/api/v2/',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"path": "/api/v2/things"')
            ->doesntExpectOutputToContain('"path": "/admin/things"');
    }

    #[Test]
    public function test_middleware_filter_keeps_only_routes_with_that_middleware(): void
    {
        Route::middleware('api')->group(function () {
            Route::get('/users', fn () => [])->name('users.index');
        });
        Route::get('/admin/things', fn () => [])->name('admin.things');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--middleware' => 'api',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"path": "/users"')
            ->expectsOutputToContain('"status": "matched"')
            ->doesntExpectOutputToContain('"path": "/admin/things"');
    }

    #[Test]
    public function test_middleware_filter_accepts_fully_qualified_class_name(): void
    {
        Route::get('/users', fn () => [])->middleware(Middleware::class)->name('users.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--middleware' => Middleware::class,
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"path": "/users"')
            ->expectsOutputToContain('"status": "matched"')
            ->doesntExpectOutputToContain('"path": "/admin/things"');
    }

    #[Test]
    public function test_middleware_filter_with_no_matches(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');
        Route::get('/internal/health', fn () => [])->name('internal.health');

        // With a filter that matches nothing, the Laravel side shrinks to empty.
        // The spec operation for /users is still listed (spec ops aren't filtered),
        // but it shows as unimplemented because the underlying route was filtered out.
        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--middleware' => 'nonexistent',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"path": "/users"')
            ->expectsOutputToContain('"status": "unimplemented"')
            ->doesntExpectOutputToContain('"path": "/admin/things"')
            ->doesntExpectOutputToContain('"path": "/internal/health"');
    }

    #[Test]
    public function test_prefix_and_middleware_combined(): void
    {
        Route::middleware('api')->group(function () {
            Route::get('/api/v2/things', fn () => [])->name('things.index');
            Route::get('/other/api-but-wrong-prefix', fn () => [])->name('other');
        });
        Route::get('/api/v2/no-middleware', fn () => [])->name('plain');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'api/v2',
            '--middleware' => 'api',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"path": "/api/v2/things"')
            ->doesntExpectOutputToContain('"path": "/api/v2/no-middleware"')
            ->doesntExpectOutputToContain('"path": "/other/api-but-wrong-prefix"');
    }

    #[Test]
    public function test_filter_header_appears_in_text_output(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'users',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Filters:')
            ->expectsOutputToContain('prefix=users');
    }

    #[Test]
    public function test_filter_header_omitted_when_no_filters(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('Filters:');
    }

    #[Test]
    public function test_prefix_filter_does_not_match_partial_path_segment(): void
    {
        Route::get('/api/v2/things', fn () => [])->name('things.index');
        Route::get('/api/v20/other', fn () => [])->name('other');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'api/v2',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->doesntExpectOutputToContain('/api/v20/other');
    }

    #[Test]
    public function test_displayed_path_includes_configured_path_prefix(): void
    {
        config(['spectator.path_prefix' => 'api/v4']);

        Route::get('/api/v4/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--prefix' => 'api/v4',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"')
            ->expectsOutputToContain('"path": "/api/v4/users"')
            ->doesntExpectOutputToContain('"path": "/users"');
    }

    #[Test]
    public function test_middleware_filter_matches_parameterized_middleware(): void
    {
        Route::get('/users', fn () => [])->middleware('throttle:60,1')->name('users.index');
        Route::get('/admin/things', fn () => [])->name('admin.things');

        $this->artisan('spectator:routes', [
            '--spec' => 'Test.v1.yml',
            '--middleware' => 'throttle',
            '--format' => 'json',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"')
            ->doesntExpectOutputToContain('"path": "/admin/things"');
    }
}
