<?php

namespace Tests\Feature\Video;

use App\Video\Media\RenderPriceSnapshot;
use Tests\TestCase;

class RenderPriceSnapshotTest extends TestCase
{
    /** @var array<string, mixed> */
    private const SPEC = [
        'pricing' => 'estimated',
        'unit_cost_usd' => 0.0336,
        'cost_estimate' => 0.0336,
        'pricing_version' => 'gemini-image-2026-09-14',
    ];

    public function test_an_item_with_no_price_of_its_own_takes_the_frozen_snapshot(): void
    {
        $item = $this->apply(['pricing' => 'unpriced', 'cost' => null]);

        $this->assertSame('estimated', $item['pricing'], 'khong duoc ket o unpriced khi registry da co gia');
        $this->assertSame(0.0336, $item['unit_cost_usd']);
        $this->assertSame(0.0336, $item['estimated_cost_usd']);
        $this->assertSame('gemini-image-2026-09-14', $item['pricing_version']);
        $this->assertNull($item['cost'], 'snapshot khong bao gio dung vao cot tien');
    }

    public function test_a_free_render_keeps_being_free_and_carries_no_estimate(): void
    {
        $item = $this->apply(['pricing' => 'free', 'cost' => 0.0]);

        $this->assertSame('free', $item['pricing']);
        $this->assertSame(0.0, $item['cost']);
        $this->assertNull($item['unit_cost_usd']);
        $this->assertNull($item['estimated_cost_usd']);
        $this->assertNull($item['pricing_version']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function confirmedStates(): iterable
    {
        yield 'reported' => ['reported'];
        yield 'reconciled' => ['reconciled'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmedStates')]
    public function test_money_the_provider_confirmed_is_never_demoted_to_an_estimate(string $pricing): void
    {
        $item = $this->apply(['pricing' => $pricing, 'cost' => 0.02]);

        $this->assertSame($pricing, $item['pricing']);
        $this->assertSame(0.02, $item['cost']);
        $this->assertArrayNotHasKey('estimated_cost_usd', $item);
        $this->assertArrayNotHasKey('unit_cost_usd', $item);
    }

    public function test_a_spec_without_a_version_leaves_the_key_empty_not_blank(): void
    {
        $item = (new RenderPriceSnapshot)->applyTo(
            ['pricing' => 'estimated', 'unit_cost_usd' => 0.015, 'cost_estimate' => 0.015, 'pricing_version' => ''],
            [['pricing' => 'estimated', 'cost' => null]],
        )[0];

        $this->assertNull($item['pricing_version']);
        $this->assertSame(0.015, $item['unit_cost_usd']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function apply(array $item): array
    {
        return (new RenderPriceSnapshot)->applyTo(self::SPEC, [$item])[0];
    }
}
