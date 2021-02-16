<?php


namespace Spectator\Concerns;


use cebe\openapi\exceptions\TypeErrorException;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;

trait HasExpectations
{
    public function expectsFalse()
    {
        return function($contents, array $exceptions) {
            $exception = $this->exceptionType($contents);

            PHPUnit::assertFalse(
                in_array($exception, $exceptions),
                $this->decodeExceptionMessage($contents)
            );
        };
    }

    public function expectsTrue()
    {
        return function($contents, array $exceptions) {
            $exception = $this->exceptionType($contents);

            PHPUnit::assertTrue(
                in_array($exception, $exceptions),
                $this->decodeExceptionMessage($contents)
            );
        };
    }

    public function exceptionType()
    {
        return function ($contents)
        {
            return Arr::get($contents, 'exception');
        };
    }

}