<?php

namespace Spectator\Coverage;

/**
 * Process-level registry that accumulates spec operations and records which ones
 * are exercised by the test suite. Uses associative set semantics so duplicate
 * recordings are idempotent.
 */
final class CoverageTracker
{
    /** @var array<string, array<string, true>> */
    private static array $specOps = [];

    /** @var array<string, array<string, true>> */
    private static array $coveredOps = [];

    /**
     * Register all operations for a spec (no-op if already registered).
     *
     * @param  array<array{method: string, path: string}>  $ops
     */
    public static function recordSpec(string $spec, array $ops): void
    {
        if (isset(self::$specOps[$spec])) {
            return;
        }

        self::$specOps[$spec] = [];

        foreach ($ops as $op) {
            $key = strtoupper($op['method']).' '.$op['path'];
            self::$specOps[$spec][$key] = true;
        }
    }

    /**
     * Mark a spec operation as covered by the running test.
     */
    public static function record(string $spec, string $method, string $path): void
    {
        $key = strtoupper($method).' '.$path;
        self::$coveredOps[$spec][$key] = true;
    }

    /**
     * Return coverage data keyed by spec name.
     *
     * @return array<string, array{total: int, covered: int, percent: float, operations: array<array{key: string, covered: bool}>}>
     */
    public static function getBySpec(): array
    {
        $result = [];

        foreach (self::$specOps as $spec => $ops) {
            $coveredOps = self::$coveredOps[$spec] ?? [];
            $total = count($ops);
            $coveredCount = count(array_intersect_key($ops, $coveredOps));

            $operations = [];
            foreach (array_keys($ops) as $key) {
                $operations[] = [
                    'key' => $key,
                    'covered' => isset($coveredOps[$key]),
                ];
            }

            $result[$spec] = [
                'total' => $total,
                'covered' => $coveredCount,
                'percent' => $total > 0 ? round(($coveredCount / $total) * 100, 1) : 0.0,
                'operations' => $operations,
            ];
        }

        return $result;
    }

    /**
     * Clear all accumulated data. Only call this at suite start or in unit tests.
     */
    public static function reset(): void
    {
        self::$specOps = [];
        self::$coveredOps = [];
    }
}
