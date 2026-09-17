<?php

declare(strict_types=1);

namespace Tests\Video\Media;

use App\Video\Media\FrameCountParser;
use Tests\TestCase;

/**
 * Luat doc so khung — chot integrity cua viec ghep.
 *
 * `format.duration` khong thay duoc mat khung vi no bam theo audio. Neu phep doc nay
 * nhan mot gia tri sai roi bao "ok", cai chot ay bien mat ma khong ai biet.
 */
class FrameCountParserTest extends TestCase
{
    /** @dataProvider rejected */
    public function test_it_refuses_anything_that_is_not_a_positive_whole_number(mixed $raw): void
    {
        // `'0'` la ca nguy hiem nhat: mot output RONG dem duoc 0 khung se "khop" voi
        // mot input cung 0, va phep kiem integrity di qua ma khong thay gi.
        $result = FrameCountParser::parse($raw);

        $this->assertFalse($result->ok, var_export($raw, true).' phai bi tu choi');
        $this->assertNull($result->frames);
        $this->assertNotNull($result->error);
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejected(): array
    {
        return [
            'khong khung' => ['0'],
            'N/A' => ['N/A'],
            'rong' => [''],
            'thap phan' => ['0.5'],
            'khoa hoc' => ['1e3'],
            'am' => ['-4'],
            'co khoang trang' => [' 12'],
            'vang mat' => [null],
            'mang' => [[24]],
            'so thuc' => [24.0],
        ];
    }

    /** @dataProvider accepted */
    public function test_it_accepts_a_positive_whole_number(mixed $raw, int $expected): void
    {
        $result = FrameCountParser::parse($raw);

        $this->assertTrue($result->ok);
        $this->assertSame($expected, $result->frames);
        $this->assertNull($result->error);
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function accepted(): array
    {
        return [
            'chuoi' => ['24', 24],
            'so nguyen' => [1536, 1536],
            'mot khung' => ['1', 1],
        ];
    }
}
