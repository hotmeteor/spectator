<?php

namespace Spectator\Tests\Console;

use Spectator\Tests\TestCase;

class ValidateSpecCommandTest extends TestCase
{
    public function test_validates_valid_spec_text_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('is valid');
    }

    public function test_validates_valid_spec_json_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"valid": true');

        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"spec": "Test.v1.yml"');
    }

    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:validate')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    public function test_fails_when_no_spec_specified_json_format(): void
    {
        $this->artisan('spectator:validate', ['--format' => 'json'])
            ->assertExitCode(1);

        // JSON output should indicate failure
        $this->artisan('spectator:validate', ['--format' => 'json'])
            ->assertExitCode(1)
            ->expectsOutputToContain('"valid": false');
    }

    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1)
            ->expectsOutputToContain('DoesNotExist.v1.yml');
    }

    public function test_fails_for_nonexistent_spec_json_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'DoesNotExist.v1.yml', '--format' => 'json'])
            ->assertExitCode(1)
            ->expectsOutputToContain('"valid": false');
    }

    public function test_validates_json_spec(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('is valid');
    }
}
