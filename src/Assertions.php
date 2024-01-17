<?php

namespace Spectator;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Spectator\Concerns\HasExpectations;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MalformedSpecException;
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
        return fn () => $this->runAssertion(function () {
            $exception = app('spectator')->requestException;

            $this->expectsFalse($exception, [
                InvalidPathException::class,
                MalformedSpecException::class,
                MissingSpecException::class,
                RequestValidationException::class,
                TypeErrorException::class,
                UnresolvableReferenceException::class,
            ]);

            return $this;
        });
    }

    public function assertInvalidRequest()
    {
        return fn () => $this->runAssertion(function () {
            $exception = app('spectator')->requestException;

            $this->expectsTrue($exception, [
                InvalidPathException::class,
                MalformedSpecException::class,
                MissingSpecException::class,
                RequestValidationException::class,
                TypeErrorException::class,
                UnresolvableReferenceException::class,
            ]);

            return $this;
        });
    }

    public function assertValidResponse()
    {
        return fn ($status = null) => $this->runAssertion(function () use ($status) {
            $exception = app('spectator')->responseException;

            $this->expectsFalse($exception, [
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
    }

    public function assertInvalidResponse()
    {
        return fn ($status = null) => $this->runAssertion(function () use ($status) {
            $exception = app('spectator')->responseException;

            $this->expectsTrue($exception, [
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
    }

    public function assertValidationMessage()
    {
        return fn ($expected) => $this->runAssertion(function () use ($expected) {
            PHPUnit::assertStringContainsString(
                $expected,
                implode(' ', $this->collectExceptionMessages()),
                'The expected error did not match the actual error.'
            );

            return $this;
        });
    }

    public function assertErrorsContain()
    {
        return fn ($errors) => $this->runAssertion(function () use ($errors) {
            $matches = 0;

            if (! is_array($errors)) {
                $errors = [$errors];
            }

            foreach ($errors as $error) {
                foreach ($this->collectExceptionMessages() as $message) {
                    if (Str::contains($message, $error)) {
                        $matches++;
                    }
                }
            }

            PHPUnit::assertNotSame(
                0,
                $matches,
                'The expected error was not found.'
            );

            return $this;
        });
    }

    public function assertPathExists()
    {
        return fn () => $this->runAssertion(function () {
            $exception = app('spectator')->requestException;

            $this->expectsFalse($exception, [
                InvalidPathException::class,
            ]);

            return $this;
        });
    }

    public function dumpSpecErrors()
    {
        return function () {
            dump($this->collectExceptionMessages());

            return $this;
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

    protected function collectExceptionMessages()
    {
        /*
         * @return array
         */
        return function (): array {
            $requestException = app('spectator')->requestException;
            $responseException = app('spectator')->responseException;

            return array_filter([
                $requestException ? urldecode($requestException->getMessage()) : null,
                $responseException ? urldecode($responseException->getMessage()) : null,
            ]);
        };
    }
}
