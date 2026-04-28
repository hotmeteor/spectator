<?php

namespace Spectator\Tests\Console;

use Illuminate\Support\Facades\File;
use Spectator\Tests\TestCase;

class StubsCommandTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = sys_get_temp_dir().'/spectator-stubs-test-'.uniqid();
        File::ensureDirectoryExists($this->outputDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);
        parent::tearDown();
    }

    public function test_generates_stub_files(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $this->assertNotEmpty(File::files($this->outputDir));
    }

    public function test_generated_class_contains_namespace(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
            '--namespace' => 'App\\Tests\\Contract',
        ])->assertExitCode(0);

        $contents = $this->getFirstFileContents();
        $this->assertStringContainsString('namespace App\\Tests\\Contract;', $contents);
    }

    public function test_generated_class_extends_base_class(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
            '--base-class' => 'PHPUnit\\Framework\\TestCase',
        ])->assertExitCode(0);

        $contents = $this->getFirstFileContents();
        $this->assertStringContainsString('extends TestCase', $contents);
        $this->assertStringContainsString('use PHPUnit\\Framework\\TestCase;', $contents);
    }

    public function test_generated_methods_use_mark_test_incomplete(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $contents = $this->getFirstFileContents();
        $this->assertStringContainsString('markTestIncomplete', $contents);
    }

    public function test_generated_setup_calls_spectator_using(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $contents = $this->getFirstFileContents();
        $this->assertStringContainsString("Spectator::using('Test.v1.yml')", $contents);
    }

    public function test_uses_operation_id_as_method_name(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $contents = $this->getFirstFileContents();
        // Test.v1.yml has operationId "get-users" → test_get_users
        $this->assertStringContainsString('test_get_users', $contents);
    }

    public function test_skips_existing_files_without_force(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $before = $this->getFirstFileContents();

        // Overwrite first-run file with sentinel content
        $firstFile = File::files($this->outputDir)[0];
        File::put($firstFile->getPathname(), '<?php // sentinel');

        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0)
            ->expectsOutputToContain('SKIP');

        $this->assertStringContainsString('sentinel', File::get($firstFile->getPathname()));
    }

    public function test_force_overwrites_existing_files(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0);

        $firstFile = File::files($this->outputDir)[0];
        File::put($firstFile->getPathname(), '<?php // sentinel');

        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertStringNotContainsString('sentinel', File::get($firstFile->getPathname()));
    }

    public function test_fails_when_no_spec_specified(): void
    {
        $this->artisan('spectator:stubs')
            ->assertExitCode(1)
            ->expectsOutputToContain('No spec file specified');
    }

    public function test_fails_for_nonexistent_spec(): void
    {
        $this->artisan('spectator:stubs', ['--spec' => 'DoesNotExist.v1.yml'])
            ->assertExitCode(1);
    }

    public function test_reports_files_written(): void
    {
        $this->artisan('spectator:stubs', [
            '--spec' => 'Test.v1.yml',
            '--output' => $this->outputDir,
        ])->assertExitCode(0)
            ->expectsOutputToContain('file(s) written');
    }

    private function getFirstFileContents(): string
    {
        $files = File::files($this->outputDir);
        $this->assertNotEmpty($files, 'No stub files were generated.');

        return File::get($files[0]->getPathname());
    }
}
