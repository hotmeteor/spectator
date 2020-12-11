<?php

namespace Spectator;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;
use cebe\openapi\exceptions\UnresolvableReferenceException;

class Assertions
{
    public function assertValidRequest()
    {
        return function () {
            $contents = $this->getContent() ? $contents = (array) $this->json() : [];

            PHPUnit::assertFalse(
                in_array(Arr::get($contents, 'exception'), [
                    RequestValidationException::class,
                    UnresolvableReferenceException::class,
                    InvalidPathException::class,
                ]),
                $this->decodeExceptionMessage($contents)
            );

            return $this;
        };
    }

    public function assertInvalidRequest()
    {
        return function () {
            $contents = (array) $this->json();

            PHPUnit::assertTrue(
                in_array(Arr::get($contents, 'exception'), [
                    RequestValidationException::class,
                    UnresolvableReferenceException::class,
                    InvalidPathException::class,
                ]),
                $this->decodeExceptionMessage($contents)
            );

            return $this;
        };
    }

    public function assertValidResponse()
    {
        return function ($status = null) {
            $contents = $this->getContent() ? (array) $this->json() : [];

            PHPUnit::assertFalse(
                in_array(Arr::get($contents, 'exception'), [ResponseValidationException::class, UnresolvableReferenceException::class]),
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
        };
    }

    public function assertInvalidResponse()
    {
        return function ($status = null) {
            $contents = (array) $this->json();

            PHPUnit::assertTrue(
                in_array(Arr::get($contents, 'exception'), [ResponseValidationException::class, UnresolvableReferenceException::class]),
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
        };
    }

    public function assertValidationMessage()
    {
        return function ($expected) {
            $actual = $this->getData()->message;

            PHPUnit::assertSame(
                $expected, $actual,
                'The expected error did not match the actual error.'
            );

            return $this;
        };
    }

    public function assertErrorsContain()
    {
        return function ($errors) {
            self::assertJson([
                'errors' => Arr::wrap($errors),
            ]);

            return $this;
        };
    }

    protected function decodeExceptionMessage()
    {
        return function ($contents) {
            return Arr::get($contents, 'message', '');
        };
    }
}
