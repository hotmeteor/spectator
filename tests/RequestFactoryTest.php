<?php

namespace Spectator\Tests;

use cebe\openapi\spec\OpenApi;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
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
}
