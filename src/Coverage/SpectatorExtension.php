<?php

namespace Spectator\Coverage;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit 11 extension that reports Spectator spec coverage after the test run.
 *
 * Register in phpunit.xml:
 *
 *   <extensions>
 *       <bootstrap class="Spectator\Coverage\SpectatorExtension">
 *           <parameter name="min_coverage" value="80"/>
 *           <parameter name="format" value="text"/>
 *       </bootstrap>
 *   </extensions>
 *
 * Parameters:
 *   min_coverage  Integer 0-100. If actual coverage falls below this value the
 *                 test run exits with a non-zero code (CI failure). Default: 0.
 *   format        "text" (default) or "json".
 */
final class SpectatorExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $minCoverage = $parameters->has('min_coverage') ? (int) $parameters->get('min_coverage') : 0;
        $format = $parameters->has('format') ? $parameters->get('format') : 'text';

        $facade->registerSubscriber(
            new class($minCoverage, $format) implements ExecutionFinishedSubscriber
            {
                public function __construct(
                    private readonly int $minCoverage,
                    private readonly string $format,
                ) {}

                public function notify(ExecutionFinished $event): void
                {
                    $bySpec = CoverageTracker::getBySpec();

                    if (empty($bySpec)) {
                        return;
                    }

                    if ($this->format === 'json') {
                        echo json_encode($bySpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

                        return;
                    }

                    $this->printTable($bySpec);
                }

                /**
                 * @param  array<string, array{total: int, covered: int, percent: float, operations: array<array{key: string, covered: bool}>}>  $bySpec
                 */
                private function printTable(array $bySpec): void
                {
                    $belowThreshold = false;

                    echo PHP_EOL.'Spectator Coverage'.PHP_EOL;
                    echo str_repeat('─', 60).PHP_EOL;

                    foreach ($bySpec as $spec => $data) {
                        echo PHP_EOL."  {$spec}: {$data['covered']}/{$data['total']} ({$data['percent']}%)".PHP_EOL;

                        foreach ($data['operations'] as $op) {
                            $mark = $op['covered'] ? '  ✔' : '  ✗';
                            echo "{$mark}  {$op['key']}".PHP_EOL;
                        }

                        if ($this->minCoverage > 0 && $data['percent'] < $this->minCoverage) {
                            $belowThreshold = true;
                            echo PHP_EOL."  ⚠ Coverage {$data['percent']}% is below the minimum {$this->minCoverage}%".PHP_EOL;
                        }
                    }

                    echo PHP_EOL;

                    if ($belowThreshold) {
                        register_shutdown_function(static fn () => exit(1));
                    }
                }
            }
        );
    }
}
