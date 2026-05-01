<?php

namespace Spectator\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use Spectator\Tests\TestCase;

class ValidateSpecCommandTest extends TestCase
{
    #[Test]
    public function test_validates_valid_spec_text_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml'])
            ->assertExitCode(0)
            ->expectsOutputToContain('is valid');
    }

    #[Test]
    public function test_validates_valid_spec_json_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"valid": true');

        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.yml', '--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"spec": "Test.v1.yml"');
    }

    #[Test]
    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:validate')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    #[Test]
    public function test_fails_when_no_spec_specified_json_format(): void
    {
        $this->artisan('spectator:validate', ['--format' => 'json'])
            ->assertExitCode(1);

        // JSON output should indicate failure
        $this->artisan('spectator:validate', ['--format' => 'json'])
            ->assertExitCode(1)
            ->expectsOutputToContain('"valid": false');
    }

    #[Test]
    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1)
            ->expectsOutputToContain('DoesNotExist.v1.yml');
    }

    #[Test]
    public function test_fails_for_nonexistent_spec_json_format(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'DoesNotExist.v1.yml', '--format' => 'json'])
            ->assertExitCode(1)
            ->expectsOutputToContain('"valid": false');
    }

    #[Test]
    public function test_validates_json_spec(): void
    {
        $this->artisan('spectator:validate', ['--spec' => 'Test.v1.json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('is valid');
    }
}
