<?php

namespace Spectator\Tests\Console;

use Illuminate\Support\Facades\Route;
use Spectator\Tests\TestCase;

class RoutesCommandTest extends TestCase
{
    public function test_shows_matched_routes(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::post('/users', fn () => [])->name('users.store');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('matched');
    }

    public function test_shows_unimplemented_when_route_missing(): void
    {
        // Deliberately register NO routes — all spec operations are unimplemented
        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('unimplemented');
    }

    public function test_shows_undocumented_when_route_not_in_spec(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::get('/not-in-spec', fn () => [])->name('extra');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('undocumented');
    }

    public function test_json_format_contains_spec_operations(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"spec_operations"')
            ->expectsOutputToContain('"spec": "Test.v1.yml"');
    }

    public function test_json_format_contains_undocumented(): void
    {
        Route::get('/not-in-spec', fn () => [])->name('extra');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"undocumented_routes"');
    }

    public function test_json_format_matched_status(): void
    {
        Route::get('/users', fn () => [])->name('users.index');
        Route::post('/users', fn () => [])->name('users.store');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "matched"');
    }

    public function test_json_format_unimplemented_status(): void
    {
        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "unimplemented"');
    }

    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:routes')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:routes', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1);
    }

    public function test_summary_line_shown(): void
    {
        Route::get('/users', fn () => [])->name('users.index');

        $this->artisan('spectator:routes', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('matched,');
    }
}
