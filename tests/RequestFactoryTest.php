<?php

namespace Spectator\Tests;

use cebe\openapi\spec\OpenApi;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spectator\Exceptions\MissingSpecException;
use Spectator\RequestFactory;

class RequestFactoryTest extends TestCase
{
    #[Test]
    public function sets_and_gets_spec_name()
    {
        $name = 'Test.v1.json';

        $factory = new RequestFactory;

        $factory->using($name);

        $this->assertSame($name, $factory->getSpec());
    }

    #[Test]
    public function resets_spec_name()
    {
        $name = 'Test.v1.json';

        $factory = new RequestFactory;

        $factory->using($name);

        $factory->reset();

        $this->assertNull($factory->getSpec());
    }

    #[Test]
    public function resolves_yaml_spec()
    {
        $name = 'Test.v1.yaml';

        $factory = new RequestFactory;

        $factory->using($name);

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame('Test.v1', $spec->info->title);
    }

    #[Test]
    public function resolves_yml_spec()
    {
        $name = 'Test.v1.yml';

        $factory = new RequestFactory;

        $factory->using($name);

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame('Test.v1', $spec->info->title);
    }

    #[Test]
    public function resolves_json_spec()
    {
        $name = 'Test.v1.json';

        $factory = new RequestFactory;

        $factory->using($name);

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame('Test.v1', $spec->info->title);
    }

    #[Test]
    public function throws_exception_on_invalid_source()
    {
        $this->expectException(MissingSpecException::class);
        $this->expectExceptionMessage('Cannot resolve schema with missing or invalid spec.');

        Config::set('spectator.default', 'invalid');

        $name = 'Test.v1.json';

        $factory = new RequestFactory;

        $factory->using($name);

        $factory->resolve();
    }

    #[Test]
    public function throws_exception_on_missing_spec_name()
    {
        $this->expectException(MissingSpecException::class);
        $this->expectExceptionMessage('Cannot resolve schema with missing or invalid spec.');

        $factory = new RequestFactory;

        $factory->resolve();
    }

    #[Test]
    public function throws_exception_on_invalid_spec_name()
    {
        $this->expectException(MissingSpecException::class);
        $this->expectExceptionMessage('Cannot resolve schema with missing or invalid spec.');

        $name = 'Missing.v1.json';

        $factory = new RequestFactory;

        $factory->using($name);

        $factory->resolve();
    }

    #[Test]
    public function throws_exception_on_invalid_spec_extension()
    {
        $this->expectException(MissingSpecException::class);
        $this->expectExceptionMessage('Cannot resolve schema with missing or invalid spec.');

        $name = 'Invalid.v1.txt';

        $factory = new RequestFactory;

        $factory->using($name);

        $factory->resolve();
    }

    #[Test]
    public function resolve_clears_captured_exceptions(): void
    {
        $factory = new RequestFactory;

        $factory->using('Test.v1.json');

        $factory->captureRequestValidation(new \RuntimeException('request error'));
        $factory->captureResponseValidation(new \RuntimeException('response error'));

        $this->assertNotNull($factory->requestException);
        $this->assertNotNull($factory->responseException);

        $factory->resolve();

        $this->assertNull($factory->requestException);
        $this->assertNull($factory->responseException);
    }

    #[Test]
    public function captures_request_validation_exception(): void
    {
        $factory = new RequestFactory;

        $exception = new \RuntimeException('request validation failed');

        $factory->captureRequestValidation($exception);

        $this->assertSame($exception, $factory->requestException);
        $this->assertNull($factory->responseException);
    }

    #[Test]
    public function captures_response_validation_exception(): void
    {
        $factory = new RequestFactory;

        $exception = new \RuntimeException('response validation failed');

        $factory->captureResponseValidation($exception);

        $this->assertSame($exception, $factory->responseException);
        $this->assertNull($factory->requestException);
    }

    #[Test]
    public function sets_and_gets_path_prefix(): void
    {
        $factory = new RequestFactory;

        $factory->setPathPrefix('v1');

        $this->assertSame('v1', $factory->getPathPrefix());
    }

    #[Test]
    public function get_path_prefix_falls_back_to_config(): void
    {
        $factory = new RequestFactory;

        Config::set('spectator.path_prefix', 'api');

        $this->assertSame('api', $factory->getPathPrefix());
    }

    #[Test]
    public function get_path_prefix_returns_empty_string_when_not_set(): void
    {
        $factory = new RequestFactory;

        Config::set('spectator.path_prefix', null);

        $this->assertSame('', $factory->getPathPrefix());
    }

    // -------------------------------------------------------------------------
    // Remote source
    // -------------------------------------------------------------------------

    #[Test]
    public function resolves_json_spec_from_remote_source(): void
    {
        $fixturesPath = realpath(__DIR__.'/Fixtures');

        Config::set('spectator.default', 'remote');
        Config::set('spectator.sources.remote', [
            'source' => 'remote',
            'base_path' => $fixturesPath,
            'params' => '',
        ]);

        $factory = new RequestFactory;
        $factory->using('Test.v1.json');

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame('Test.v1', $spec->info->title);
    }

    #[Test]
    public function resolves_yaml_spec_from_remote_source(): void
    {
        $fixturesPath = realpath(__DIR__.'/Fixtures');

        Config::set('spectator.default', 'remote');
        Config::set('spectator.sources.remote', [
            'source' => 'remote',
            'base_path' => $fixturesPath,
            'params' => '',
        ]);

        $factory = new RequestFactory;
        $factory->using('Test.v1.yml');

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame('Test.v1', $spec->info->title);
    }

    #[Test]
    public function remote_source_constructs_url_with_params(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getRemotePath');

        $url = $method->invoke($factory, [
            'source' => 'remote',
            'base_path' => 'https://example.com/specs',
            'params' => '?token=abc123',
        ], 'Api.v1.yml');

        $this->assertSame('https://example.com/specs/Api.v1.yml?token=abc123', $url);
    }

    #[Test]
    public function remote_source_constructs_url_without_params(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getRemotePath');

        $url = $method->invoke($factory, [
            'source' => 'remote',
            'base_path' => 'https://example.com/specs',
            'params' => '',
        ], 'Api.v1.yml');

        $this->assertSame('https://example.com/specs/Api.v1.yml', $url);
    }

    #[Test]
    public function remote_source_adds_trailing_slash_to_base_path(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getRemotePath');

        $urlWithSlash = $method->invoke($factory, ['source' => 'remote', 'base_path' => 'https://example.com/specs/', 'params' => ''], 'Api.v1.yml');
        $urlWithoutSlash = $method->invoke($factory, ['source' => 'remote', 'base_path' => 'https://example.com/specs', 'params' => ''], 'Api.v1.yml');

        $this->assertSame($urlWithSlash, $urlWithoutSlash);
    }

    // -------------------------------------------------------------------------
    // GitHub source
    // -------------------------------------------------------------------------

    #[Test]
    public function github_source_constructs_correct_url(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getGithubPath');

        $url = $method->invoke($factory, [
            'source' => 'github',
            'token' => 'ghp_mytoken',
            'repo' => 'org/my-repo',
            'base_path' => 'main/specs',
        ], 'Api.v1.yml');

        $this->assertSame('https://ghp_mytoken@raw.githubusercontent.com/org/my-repo/main/specs/Api.v1.yml', $url);
    }

    #[Test]
    public function github_source_trims_trailing_slash_from_base_path(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getGithubPath');

        $urlWithSlash = $method->invoke($factory, [
            'source' => 'github',
            'token' => 'ghp_mytoken',
            'repo' => 'org/my-repo',
            'base_path' => 'main/specs/',
        ], 'Api.v1.yml');

        $urlWithoutSlash = $method->invoke($factory, [
            'source' => 'github',
            'token' => 'ghp_mytoken',
            'repo' => 'org/my-repo',
            'base_path' => 'main/specs',
        ], 'Api.v1.yml');

        $this->assertSame($urlWithoutSlash, $urlWithSlash);
        $this->assertStringNotContainsString('//', str_replace('https://', '', $urlWithSlash));
    }

    #[Test]
    public function github_source_trims_leading_slash_from_base_path(): void
    {
        $factory = new RequestFactory;

        $method = new ReflectionMethod($factory, 'getGithubPath');

        $url = $method->invoke($factory, [
            'source' => 'github',
            'token' => 'ghp_mytoken',
            'repo' => 'org/my-repo',
            'base_path' => '/main/specs',
        ], 'Api.v1.yml');

        $this->assertSame('https://ghp_mytoken@raw.githubusercontent.com/org/my-repo/main/specs/Api.v1.yml', $url);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function specFileProvider(): array
    {
        return [
            'json file' => ['Test.v1.json', 'Test.v1'],
            'yml file' => ['Test.v1.yml', 'Test.v1'],
            'yaml file' => ['Test.v1.yaml', 'Test.v1'],
        ];
    }

    #[Test]
    #[DataProvider('specFileProvider')]
    public function resolves_spec_from_remote_source_for_different_formats(string $specFile, string $expectedTitle): void
    {
        $fixturesPath = realpath(__DIR__.'/Fixtures');

        Config::set('spectator.default', 'remote');
        Config::set('spectator.sources.remote', [
            'source' => 'remote',
            'base_path' => $fixturesPath,
            'params' => '',
        ]);

        $factory = new RequestFactory;
        $factory->using($specFile);

        $spec = $factory->resolve();

        $this->assertInstanceOf(OpenApi::class, $spec);
        $this->assertSame($expectedTitle, $spec->info->title);
    }
}
