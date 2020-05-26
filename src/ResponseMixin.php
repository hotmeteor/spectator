<?php

namespace Spectator;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;

class ResponseMixin
{
    public function assertValidRequest()
    {
        return function () {
            self::assertJsonMissing(['exception' => RequestValidationException::class]);

            return $this;
        };
    }

    public function assertInvalidRequest()
    {
        return function () {
            self::assertJsonFragment(['exception' => RequestValidationException::class]);

            return $this;
        };
    }

    public function assertValidResponse()
    {
        return function () {
            self::assertJsonMissing(['exception' => ResponseValidationException::class]);

            return $this;
        };
    }

    public function assertInvalidResponse()
    {
        return function () {
            self::assertJsonFragment(['exception' => ResponseValidationException::class]);

            return $this;
        };
    }

    public function assertValidationMessage()
    {
        return function ($expected) {
            $actual = $this->jsonGet('message');

            PHPUnit::assertSame(
                $expected, $actual,
                "The expected error [{$expected}] did not match the actual error of {$actual}."
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
}
