<?php

namespace Spectator;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\ExpectationFailedException;
use Spectator\Concerns\HasExpectations;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;

/**
 * @mixin \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
class Assertions
{
    use HasExpectations;

    public function assertValidRequest(): Closure
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

    public function assertInvalidRequest(): Closure
    {
        return fn () => $this->runAssertion(function () {
            $exception = app('spectator')->requestException;

            $this->expectsFalse($exception, [
                MalformedSpecException::class,
                MissingSpecException::class,
                TypeErrorException::class,
                UnresolvableReferenceException::class,
            ]);

            $this->expectsTrue($exception, [
                InvalidPathException::class,
                RequestValidationException::class,
            ], 'Failed asserting that the request is invalid.');

            return $this;
        });
    }

    public function assertValidResponse(): Closure
    {
        return fn (?int $status = null) => $this->runAssertion(function () use ($status) {
            if ($status) {
                $this->assertStatus($status);
            }

            $exception = app('spectator')->responseException;

            $this->expectsFalse($exception, [
                InvalidPathException::class,
                MalformedSpecException::class,
                MissingSpecException::class,
                ResponseValidationException::class,
                TypeErrorException::class,
                UnresolvableReferenceException::class,
            ]);

            return $this;
        });
    }

    public function assertInvalidResponse(): Closure
    {
        return fn (?int $status = null) => $this->runAssertion(function () use ($status) {
            if ($status) {
                $this->assertStatus($status);
            }

            $exception = app('spectator')->responseException;

            $this->expectsFalse($exception, [
                MalformedSpecException::class,
                MissingSpecException::class,
                TypeErrorException::class,
                UnresolvableReferenceException::class,
            ]);

            $this->expectsTrue($exception, [
                InvalidPathException::class,
                ResponseValidationException::class,
            ], 'Failed asserting that the response is invalid.');

            return $this;
        });
    }

    public function assertValidationMessage(): Closure
    {
        return fn (string $expected) => $this->runAssertion(function () use ($expected) {
            PHPUnit::assertStringContainsString(
                $expected,
                implode(' ', $this->collectExceptionMessages()),
                'The expected error did not match the actual error.'
            );

            return $this;
        });
    }

    public function assertErrorsContain(): Closure
    {
        /**
         * @param  string|array<int, string>  $errors
         */
        return fn (string|array $errors) => $this->runAssertion(function () use ($errors) {
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

    public function assertPathExists(): Closure
    {
        return fn () => $this->runAssertion(function () {
            $exception = app('spectator')->requestException;

            $this->expectsFalse($exception, [
                InvalidPathException::class,
            ]);

            return $this;
        });
    }

    public function dumpSpecErrors(): Closure
    {
        return function () {
            dump($this->collectExceptionMessages());

            return $this;
        };
    }

    protected function runAssertion(): Closure
    {
        return function (Closure $closure) {
            $original = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6)[5];

            try {
                return $closure();
            } catch (ExpectationFailedException $exception) {
                throw new \ErrorException($exception->getMessage(), $exception->getCode(), E_WARNING, $original['file'], $original['line'], $exception);
            }
        };
    }

    protected function collectExceptionMessages(): Closure
    {
        /**
         * @return array<int, string|null>
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
