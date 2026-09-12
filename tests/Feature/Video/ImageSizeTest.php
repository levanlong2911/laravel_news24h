<?php

namespace Tests\Feature\Video;

use App\Enums\ImageSize;
use Tests\TestCase;

class ImageSizeTest extends TestCase
{
    private const MAX_EDGE = 3840;

    private const MAX_RATIO = 3;

    private const MIN_PIXELS = 655_360;

    private const MAX_PIXELS = 8_294_400;

    public function test_every_offered_size_satisfies_all_four_provider_constraints(): void
    {
        foreach (ImageSize::cases() as $size) {
            $width = $size->width();
            $height = $size->height();
            $pixels = $width * $height;
            $at = $size->name.' ('.$size->value.')';

            $this->assertSame(0, $width % 16, $at.': width must be a multiple of 16');
            $this->assertSame(0, $height % 16, $at.': height must be a multiple of 16');

            $this->assertLessThanOrEqual(self::MAX_EDGE, max($width, $height), $at.': longest edge');

            $this->assertLessThanOrEqual(
                self::MAX_RATIO * min($width, $height),
                max($width, $height),
                $at.': long-to-short ratio must stay within 3:1',
            );

            $this->assertGreaterThanOrEqual(self::MIN_PIXELS, $pixels, $at.': total pixels floor');
            $this->assertLessThanOrEqual(self::MAX_PIXELS, $pixels, $at.': total pixels ceiling');
        }
    }

    public function test_the_three_vertical_sizes_are_exactly_nine_by_sixteen(): void
    {
        $vertical = [ImageSize::VERTICAL_HD, ImageSize::VERTICAL_2K, ImageSize::PORTRAIT_4K];

        foreach ($vertical as $size) {
            $this->assertSame(
                $size->height() * 9,
                $size->width() * 16,
                $size->value.' must be exactly 9:16',
            );

            $this->assertStringEndsWith('(9:16)', $size->label());
        }
    }

    /**
     * `PORTRAIT` doc la 2:3. Ai chon no vi tuong "doc = 9:16" se sai kho anh,
     * nen chot nay giu no o dung ty le cua no.
     */
    public function test_the_older_portrait_size_is_two_by_three_not_nine_by_sixteen(): void
    {
        $this->assertSame('1024x1536', ImageSize::PORTRAIT->value);
        $this->assertStringEndsWith('(2:3)', ImageSize::PORTRAIT->label());
        $this->assertNotSame(ImageSize::PORTRAIT->height() * 9, ImageSize::PORTRAIT->width() * 16);
    }

    public function test_the_vertical_sizes_span_a_draft_and_a_working_canvas(): void
    {
        $this->assertLessThan(
            ImageSize::VERTICAL_2K->width() * ImageSize::VERTICAL_2K->height(),
            ImageSize::VERTICAL_HD->width() * ImageSize::VERTICAL_HD->height(),
        );

        $this->assertLessThan(
            ImageSize::PORTRAIT_4K->width() * ImageSize::PORTRAIT_4K->height(),
            ImageSize::VERTICAL_2K->width() * ImageSize::VERTICAL_2K->height(),
        );

        $this->assertSame(self::MAX_PIXELS, ImageSize::PORTRAIT_4K->width() * ImageSize::PORTRAIT_4K->height());
    }
}
