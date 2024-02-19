<?php

namespace Spectator\Concerns;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

trait HasExpectations
{
    public function expectsFalse(): Closure
    {
        /**
         * @param  array<int, class-string>  $exceptions
         */
        return function (?Throwable $throwable = null, array $exceptions = []): void {
            if ($throwable) {
                $class = get_class($throwable);

                PHPUnit::assertFalse(
                    in_array($class, $exceptions),
                    $throwable->getMessage(),
                );
            } else {
                PHPUnit::assertFalse(false);
            }
        };
    }

    public function expectsTrue(): Closure
    {
        /**
         * @param  array<int, class-string>  $exceptions
         */
        return function (?Throwable $throwable = null, array $exceptions = [], string $message = ''): void {
            if ($throwable) {
                $class = get_class($throwable);

                PHPUnit::assertTrue(
                    in_array($class, $exceptions),
                    $throwable->getMessage(),
                );
            } else {
                PHPUnit::assertTrue(false, $message);
            }
        };
    }
}
