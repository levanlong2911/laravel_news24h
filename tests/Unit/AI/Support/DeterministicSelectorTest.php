<?php

namespace Tests\Unit\AI\Support;

use App\Services\AI\Support\DeterministicSelector;
use PHPUnit\Framework\TestCase;

final class DeterministicSelectorTest extends TestCase
{
    public function test_same_seed_always_returns_same_item(): void
    {
        $items = ['a', 'b', 'c'];

        $this->assertSame(
            DeterministicSelector::pick('seed-xyz', $items),
            DeterministicSelector::pick('seed-xyz', $items),
        );
    }

    public function test_all_items_are_reachable(): void
    {
        $items = ['alpha', 'beta', 'gamma'];
        $seen  = [];

        for ($i = 0; $i < 1000; $i++) {
            $seen[DeterministicSelector::pick("probe-{$i}", $items)] = true;
        }

        $this->assertCount(count($items), $seen, 'Not all items were selected across 1000 seeds');
    }

    public function test_single_item_array_always_returns_that_item(): void
    {
        $this->assertSame('only', DeterministicSelector::pick('any-seed', ['only']));
        $this->assertSame('only', DeterministicSelector::pick('', ['only']));
    }

    public function test_different_seeds_can_select_different_items(): void
    {
        $items   = ['x', 'y'];
        $results = array_unique(array_map(
            fn ($i) => DeterministicSelector::pick("s{$i}", $items),
            range(0, 99),
        ));

        $this->assertCount(2, $results);
    }

    public function test_empty_array_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('non-empty array');

        DeterministicSelector::pick('seed', []);
    }
}
