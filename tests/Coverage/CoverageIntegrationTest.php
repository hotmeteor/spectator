<?php

namespace Spectator\Tests\Coverage;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spectator\Coverage\CoverageTracker;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\Tests\TestCase;

class CoverageIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CoverageTracker::reset();
    }

    #[Test]
    public function test_middleware_records_spec_on_first_request(): void
    {
        Spectator::using('Test.v1.yml');

        Route::get('/users', fn () => response()->json([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ]))->middleware(Middleware::class);

        $this->getJson('/users');

        $data = CoverageTracker::getBySpec();
        $this->assertArrayHasKey('Test.v1.yml', $data);
        $this->assertGreaterThan(0, $data['Test.v1.yml']['total']);
    }

    #[Test]
    public function test_middleware_records_covered_operation(): void
    {
        Spectator::using('Test.v1.yml');

        Route::get('/users', fn () => response()->json([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ]))->middleware(Middleware::class);

        $this->getJson('/users');

        $data = CoverageTracker::getBySpec();
        $this->assertGreaterThan(0, $data['Test.v1.yml']['covered']);

        $coveredKeys = array_column(
            array_filter($data['Test.v1.yml']['operations'], fn ($op) => $op['covered']),
            'key'
        );
        $this->assertContains('GET /users', $coveredKeys);
    }

    #[Test]
    public function test_middleware_records_spec_operations_only_once(): void
    {
        Spectator::using('Test.v1.yml');

        Route::get('/users', fn () => response()->json([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ]))->middleware(Middleware::class);

        $this->getJson('/users');
        $afterFirst = CoverageTracker::getBySpec()['Test.v1.yml']['total'];

        // A second request must NOT change the total operation count
        $this->getJson('/users');
        $afterSecond = CoverageTracker::getBySpec()['Test.v1.yml']['total'];

        $this->assertSame($afterFirst, $afterSecond);
    }

    #[Test]
    public function test_noop_when_no_spec_set(): void
    {
        Spectator::using(null);

        Route::get('/users', fn () => response()->noContent())->middleware(Middleware::class);

        $this->getJson('/users');

        $this->assertEmpty(CoverageTracker::getBySpec());
    }
}
