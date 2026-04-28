<?php

namespace Spectator\Tests\Console;

use Spectator\Tests\TestCase;

class CoverageCommandTest extends TestCase
{
    public function test_lists_operations_text_format(): void
    {
        $this->artisan('spectator:coverage', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Operations in Test.v1.yml')
            ->expectsOutputToContain('GET')
            ->expectsOutputToContain('/users');
    }

    public function test_lists_operations_json_format(): void
    {
        $this->artisan('spectator:coverage', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"spec": "Test.v1.yml"')
            ->expectsOutputToContain('"operations"');
    }

    public function test_json_output_contains_operations(): void
    {
        $this->artisan('spectator:coverage', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"method": "GET"')
            ->expectsOutputToContain('"path": "/users"');
    }

    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:coverage')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:coverage', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1);
    }

    public function test_text_format_shows_operation_count(): void
    {
        $this->artisan('spectator:coverage', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('operation(s) found');
    }
}
