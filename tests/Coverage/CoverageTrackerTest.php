<?php

namespace Spectator\Tests\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spectator\Coverage\CoverageTracker;

class CoverageTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        CoverageTracker::reset();
    }

    #[Test]
    public function test_records_spec_operations(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
            ['method' => 'POST', 'path' => '/users'],
        ]);

        $data = CoverageTracker::getBySpec();
        $this->assertArrayHasKey('Api.v1.yml', $data);
        $this->assertSame(2, $data['Api.v1.yml']['total']);
        $this->assertSame(0, $data['Api.v1.yml']['covered']);
    }

    #[Test]
    public function test_records_covered_operation(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
            ['method' => 'POST', 'path' => '/users'],
        ]);

        CoverageTracker::record('Api.v1.yml', 'GET', '/users');

        $data = CoverageTracker::getBySpec();
        $this->assertSame(1, $data['Api.v1.yml']['covered']);
        $this->assertSame(50.0, $data['Api.v1.yml']['percent']);
    }

    #[Test]
    public function test_spec_recorded_only_once(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
        ]);

        // Second call with more operations must be ignored (idempotent guard)
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
            ['method' => 'POST', 'path' => '/users'],
        ]);

        $data = CoverageTracker::getBySpec();
        $this->assertSame(1, $data['Api.v1.yml']['total']);
    }

    #[Test]
    public function test_covered_ops_use_set_semantics(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
        ]);

        CoverageTracker::record('Api.v1.yml', 'GET', '/users');
        CoverageTracker::record('Api.v1.yml', 'GET', '/users');
        CoverageTracker::record('Api.v1.yml', 'GET', '/users');

        $data = CoverageTracker::getBySpec();
        $this->assertSame(1, $data['Api.v1.yml']['covered']);
    }

    #[Test]
    public function test_normalizes_method_to_uppercase(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'get', 'path' => '/users'],
        ]);

        CoverageTracker::record('Api.v1.yml', 'get', '/users');

        $data = CoverageTracker::getBySpec();
        $this->assertSame(1, $data['Api.v1.yml']['covered']);
    }

    #[Test]
    public function test_reset_clears_all_data(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [['method' => 'GET', 'path' => '/users']]);
        CoverageTracker::record('Api.v1.yml', 'GET', '/users');

        CoverageTracker::reset();

        $this->assertEmpty(CoverageTracker::getBySpec());
    }

    #[Test]
    public function test_tracks_multiple_specs_independently(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [['method' => 'GET', 'path' => '/users']]);
        CoverageTracker::recordSpec('Admin.v1.yml', [['method' => 'GET', 'path' => '/admin']]);

        CoverageTracker::record('Api.v1.yml', 'GET', '/users');

        $data = CoverageTracker::getBySpec();
        $this->assertSame(100.0, $data['Api.v1.yml']['percent']);
        $this->assertSame(0.0, $data['Admin.v1.yml']['percent']);
    }

    #[Test]
    public function test_percent_is_zero_for_empty_spec(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', []);

        $data = CoverageTracker::getBySpec();
        $this->assertSame(0.0, $data['Api.v1.yml']['percent']);
    }

    #[Test]
    public function test_operations_contain_covered_flag(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
            ['method' => 'POST', 'path' => '/users'],
        ]);

        CoverageTracker::record('Api.v1.yml', 'GET', '/users');

        $data = CoverageTracker::getBySpec();
        $ops = $data['Api.v1.yml']['operations'];

        $this->assertCount(2, $ops);
        $getOp = array_values(array_filter($ops, fn ($op) => $op['key'] === 'GET /users'))[0];
        $postOp = array_values(array_filter($ops, fn ($op) => $op['key'] === 'POST /users'))[0];

        $this->assertTrue($getOp['covered']);
        $this->assertFalse($postOp['covered']);
    }

    #[Test]
    public function test_uncovered_ops_not_counted(): void
    {
        CoverageTracker::recordSpec('Api.v1.yml', [
            ['method' => 'GET', 'path' => '/users'],
            ['method' => 'PUT', 'path' => '/users/{id}'],
        ]);

        // Record an operation not in the spec — must not inflate covered count
        CoverageTracker::record('Api.v1.yml', 'DELETE', '/users/{id}');

        $data = CoverageTracker::getBySpec();
        // covered is intersection of spec ops and recorded ops
        $this->assertSame(0, $data['Api.v1.yml']['covered']);
    }
}
