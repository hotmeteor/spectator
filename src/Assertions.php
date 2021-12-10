<?php

namespace Spectator;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use Closure;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use Spectator\Concerns\HasExpectations;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;

/**
 * @mixin \Illuminate\Testing\TestResponse|Illuminate\Foundation\Testing\TestResponse
 */
class Assertions
{
    use HasExpectations;

    public function assertValidRequest()
    {
        return function () {
            return $this->runAssertion(function () {
                $contents = $this->getContent() ? $contents = (array) $this->json() : [];

                $this->expectsFalse($contents, [
                    InvalidPathException::class,
                    MissingSpecException::class,
                    RequestValidationException::class,
                    TypeErrorException::class,
                    UnresolvableReferenceException::class,
                ]);

                return $this;
            });
        };
    }

    public function assertInvalidRequest()
    {
        return function () {
            return $this->runAssertion(function () {
                $contents = (array) $this->json();

                $this->expectsTrue($contents, [
                    InvalidPathException::class,
                    MissingSpecException::class,
                    RequestValidationException::class,
                    TypeErrorException::class,
                    UnresolvableReferenceException::class,
                ]);

                return $this;
            });
        };
    }

    public function assertValidResponse()
    {
        return function ($status = null) {
            return $this->runAssertion(function () use ($status) {
                $contents = $this->getContent() ? (array) $this->json() : [];

                $this->expectsFalse($contents, [
                    ResponseValidationException::class,
                    TypeErrorException::class,
                    UnresolvableReferenceException::class,
                ]);

                if ($status) {
                    $actual = $this->getStatusCode();

                    PHPUnit::assertTrue(
                        $actual === $status,
                        "Expected status code {$status} but received {$actual}."
                    );
                }

                return $this;
            });
        };
    }

    public function assertInvalidResponse()
    {
        return function ($status = null) {
            return $this->runAssertion(function () use ($status) {
                $contents = (array) $this->json();

                $this->expectsTrue($contents, [
                    ResponseValidationException::class,
                    TypeErrorException::class,
                    UnresolvableReferenceException::class,
                ]);

                if ($status) {
                    $actual = $this->getStatusCode();

                    PHPUnit::assertTrue(
                        $actual === $status,
                        "Expected status code {$status} but received {$actual}."
                    );
                }

                return $this;
            });
        };
    }

    public function assertValidationMessage()
    {
        return function ($expected) {
            return $this->runAssertion(function () use ($expected) {
                $actual = $this->decodeExceptionMessage((array) $this->json());

                PHPUnit::assertStringContainsString(
                    $expected,
                    $actual,
                    'The expected error did not match the actual error.'
                );

                return $this;
            });
        };
    }

    public function assertErrorsContain()
    {
        return function ($errors) {
            return $this->runAssertion(function () use ($errors) {
                self::assertJson([
                    'specErrors' => Arr::wrap($errors),
                ]);

                return $this;
            });
        };
    }

    protected function runAssertion()
    {
        return function (Closure $closure) {
            $original = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6)[5];

            try {
                return $closure();
            } catch (\Exception $exception) {
                throw new \ErrorException($exception->getMessage(), $exception->getCode(), E_WARNING, $original['file'], $original['line']);
            }
        };
    }
}
