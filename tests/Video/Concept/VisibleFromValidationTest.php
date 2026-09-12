<?php

namespace Tests\Video\Concept;

use App\Video\Concept\SonnetScreenConceptDesigner;
use App\Video\Concept\Viewpoint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `visible_from` do Sonnet tu khai theo tung signature feature, roi di thang
 * sang Python de dung prompt. Nen Laravel phai la cho GAC: giu view hop le
 * Sonnet tra, loc view la, thieu/rong thi tra ve ca ba view.
 *
 * Neu de mot view la lot qua, Python se nhan mot goc may quay khong ton tai.
 */
class VisibleFromValidationTest extends TestCase
{
    /** @return list<string> */
    private function validate(mixed $visibleFrom): array
    {
        $method = new ReflectionMethod(SonnetScreenConceptDesigner::class, 'validVisibleFrom');
        $method->setAccessible(true);

        return $method->invoke(
            (new ReflectionClass(SonnetScreenConceptDesigner::class))->newInstanceWithoutConstructor(),
            $visibleFrom,
        );
    }

    /** @return list<string> */
    private function allViews(): array
    {
        return array_column(Viewpoint::cases(), 'value');
    }

    /**
     * RANH GIOI CO Y: khong khai lai ba chuoi o day. Bai test nay phai do khi
     * enum doi ma `validVisibleFrom()` khong doi theo — do la ca ly do no ton tai.
     */
    public function test_the_fallback_is_the_viewpoint_enum_not_a_second_hard_coded_list(): void
    {
        $this->assertSame($this->allViews(), $this->validate(null));
    }

    /** @dataProvider missingOrUnusable */
    public function test_a_missing_or_unusable_value_falls_back_to_every_view(string $label, mixed $input): void
    {
        $this->assertSame($this->allViews(), $this->validate($input), $label);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function missingOrUnusable(): array
    {
        return [
            'thieu han' => ['null', null],
            'khong phai mang' => ['chuoi tran', 'side'],
            'mang rong' => ['[]', []],
            'toan view la' => ['top_down + bottom', ['top_down', 'bottom']],
            'sai hoa thuong' => ['SIDE', ['SIDE']],
            'toan phan tu sai kieu' => ['[123, null]', [123, null]],
        ];
    }

    public function test_a_complete_answer_is_kept_as_it_is(): void
    {
        $this->assertSame($this->allViews(), $this->validate($this->allViews()));
    }

    /**
     * Feature chi nhin thay tu MOT goc la thong tin THAT — khong duoc nong len
     * ba view, vi lam vay la bia ra hai goc nhin Sonnet khong he khang dinh.
     */
    public function test_a_single_valid_view_is_not_inflated_to_three(): void
    {
        $this->assertSame(['side'], $this->validate(['side']));
    }

    public function test_an_unknown_view_is_dropped_while_the_valid_ones_survive(): void
    {
        $this->assertSame(
            ['side', 'rear_three_quarter'],
            $this->validate(['side', 'top_down', 'rear_three_quarter']),
        );
    }

    public function test_a_repeated_view_is_only_kept_once(): void
    {
        $this->assertSame(['side'], $this->validate(['side', 'side']));
    }

    public function test_surrounding_whitespace_does_not_make_a_view_unknown(): void
    {
        $this->assertSame(['side'], $this->validate(['  side  ']));
    }

    public function test_a_non_string_entry_is_skipped_without_losing_the_rest(): void
    {
        $this->assertSame(['side'], $this->validate(['side', 123, null]));
    }

    /**
     * Thu tu phai theo dung thu tu Sonnet tra, khong sap xep lai: no la thu tu
     * uu tien cua nguoi thiet ke, khong phai tap hop khong thu tu.
     */
    public function test_the_order_the_model_gave_is_preserved(): void
    {
        $this->assertSame(
            ['rear_three_quarter', 'front_three_quarter'],
            $this->validate(['rear_three_quarter', 'front_three_quarter']),
        );
    }
}
