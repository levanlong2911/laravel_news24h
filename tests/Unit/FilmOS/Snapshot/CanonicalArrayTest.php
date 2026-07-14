<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Snapshot;

use App\Services\AI\FilmOS\Snapshot\CanonicalArray;
use App\Services\AI\FilmOS\Snapshot\CircularCanonicalizationException;
use PHPUnit\Framework\TestCase;

final class CanonicalArrayTest extends TestCase
{
    // ── Associative arrays: keys sorted ──────────────────────────────────────

    /** @test */
    public function flat_associative_array_is_sorted_by_key(): void
    {
        $input    = ['z' => 3, 'a' => 1, 'm' => 2];
        $result   = CanonicalArray::deepSort($input);

        $this->assertSame(['a' => 1, 'm' => 2, 'z' => 3], $result);
    }

    /** @test */
    public function nested_associative_arrays_are_sorted_recursively(): void
    {
        $input = [
            'outer_z' => ['inner_b' => 2, 'inner_a' => 1],
            'outer_a' => ['inner_y' => 9, 'inner_x' => 8],
        ];

        $result = CanonicalArray::deepSort($input);

        $this->assertSame(['inner_x', 'inner_y'], array_keys($result['outer_a']));
        $this->assertSame(['inner_a', 'inner_b'], array_keys($result['outer_z']));
        $this->assertSame(['outer_a', 'outer_z'], array_keys($result));
    }

    /** @test */
    public function deeply_nested_three_levels_are_sorted(): void
    {
        $input = [
            'c' => ['b' => ['z' => 0, 'a' => 1], 'a' => 'leaf'],
            'a' => 'top',
        ];

        $result = CanonicalArray::deepSort($input);

        $this->assertSame(['a', 'c'], array_keys($result));
        $this->assertSame(['a', 'b'], array_keys($result['c']));
        $this->assertSame(['a', 'z'], array_keys($result['c']['b']));
    }

    // ── List arrays: order preserved ─────────────────────────────────────────

    /** @test */
    public function sequential_list_array_preserves_insertion_order(): void
    {
        $input  = ['banana', 'apple', 'cherry'];
        $result = CanonicalArray::deepSort($input);

        $this->assertSame(['banana', 'apple', 'cherry'], $result);
    }

    /** @test */
    public function list_of_associative_arrays_sorts_values_but_not_list_order(): void
    {
        $input = [
            ['z' => 3, 'a' => 1],
            ['m' => 2, 'b' => 9],
        ];

        $result = CanonicalArray::deepSort($input);

        // List order preserved; inner keys sorted
        $this->assertSame(['a' => 1, 'z' => 3], $result[0]);
        $this->assertSame(['b' => 9, 'm' => 2], $result[1]);
    }

    /** @test */
    public function empty_list_is_returned_unchanged(): void
    {
        $this->assertSame([], CanonicalArray::deepSort([]));
    }

    // ── Scalar passthrough ────────────────────────────────────────────────────

    /** @test */
    public function scalars_are_returned_unchanged(): void
    {
        $this->assertSame('hello', CanonicalArray::deepSort('hello'));
        $this->assertSame(42,      CanonicalArray::deepSort(42));
        $this->assertNull(CanonicalArray::deepSort(null));
        $this->assertTrue(CanonicalArray::deepSort(true));
    }

    // ── Hash stability ────────────────────────────────────────────────────────

    /** @test */
    public function two_arrays_with_different_key_insertion_order_produce_same_sorted_output(): void
    {
        $a = ['z' => 'last', 'a' => 'first', 'm' => 'middle'];
        $b = ['m' => 'middle', 'z' => 'last', 'a' => 'first'];

        $this->assertSame(
            json_encode(CanonicalArray::deepSort($a)),
            json_encode(CanonicalArray::deepSort($b)),
        );
    }

    // ── Depth guard ───────────────────────────────────────────────────────────

    /** @test */
    public function exceeding_max_depth_throws_circular_canonicalization_exception(): void
    {
        $this->expectException(CircularCanonicalizationException::class);
        $this->expectExceptionMessageMatches('/maximum recursion depth/');

        // Build an array 33 levels deep (MAX_DEPTH = 32)
        $deep = 'leaf';
        for ($i = 0; $i < 33; $i++) {
            $deep = ['nested' => $deep];
        }

        CanonicalArray::deepSort($deep);
    }
}
