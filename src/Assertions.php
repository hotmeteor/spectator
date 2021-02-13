<?php

namespace Spectator;

use cebe\openapi\exceptions\UnresolvableReferenceException;
use Closure;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;

/** @mixin \Illuminate\Testing\TestResponse|Illuminate\Foundation\Testing\TestResponse */
class Assertions
{
    public function assertValidRequest()
    {
        return function () {
            return $this->runAssertion(function () {
                $contents = $this->getContent() ? $contents = (array) $this->json() : [];

                PHPUnit::assertFalse(
                    in_array(Arr::get($contents, 'exception'), [
                        InvalidPathException::class,
                        MissingSpecException::class,
                        RequestValidationException::class,
                        UnresolvableReferenceException::class,
                    ]),
                    $this->decodeExceptionMessage($contents)
                );

                return $this;
            });
        };
    }

    public function assertInvalidRequest()
    {
        return function () {
            return $this->runAssertion(function () {
                $contents = (array) $this->json();

                PHPUnit::assertTrue(
                    in_array(Arr::get($contents, 'exception'), [
                        InvalidPathException::class,
                        MissingSpecException::class,
                        RequestValidationException::class,
                        UnresolvableReferenceException::class,
                    ]),
                    $this->decodeExceptionMessage($contents)
                );

                return $this;
            });
        };
    }

    public function assertValidResponse()
    {
        return function ($status = null) {
            return $this->runAssertion(function () use ($status) {
                $contents = $this->getContent() ? (array) $this->json() : [];

                PHPUnit::assertFalse(
                    in_array(Arr::get($contents, 'exception'), [
                        ResponseValidationException::class,
                        UnresolvableReferenceException::class,
                    ]),
                    $this->decodeExceptionMessage($contents)
                );

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

                PHPUnit::assertTrue(
                    in_array(Arr::get($contents, 'exception'), [
                        ResponseValidationException::class,
                        UnresolvableReferenceException::class,
                    ]),
                    $this->decodeExceptionMessage($contents)
                );

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
                $actual = $this->getData()->message;

                PHPUnit::assertSame(
                    $expected, $actual,
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
                    'errors' => Arr::wrap($errors),
                ]);

                return $this;
            });
        };
    }

    protected function decodeExceptionMessage()
    {
        return function ($contents) {
            return Arr::get($contents, 'message', '');
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
