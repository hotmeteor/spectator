<?php

namespace Spectator\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;

trait HasExpectations
{
    public function expectsFalse()
    {
        /*
         * @param Throwable|null $throwable
         * @param array $exceptions
         * @return void
         */
        return function (Throwable $throwable = null, array $exceptions = []) {
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

    public function expectsTrue()
    {
        /*
         * @param Throwable|null $throwable
         * @param array $exceptions
         * @return void
         */
        return function (Throwable $throwable = null, array $exceptions = []) {
            if ($throwable) {
                $class = get_class($throwable);

                PHPUnit::assertTrue(
                    in_array($class, $exceptions),
                    $throwable->getMessage(),
                );
            } else {
                PHPUnit::assertTrue(true);
            }
        };
    }
}
