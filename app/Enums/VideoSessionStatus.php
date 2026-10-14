<?php

namespace App\Enums;

use App\Traits\EnumTrait;

enum VideoSessionStatus: string
{
    use EnumTrait;

    case DRAFT = 'draft';
    case PLANNING = 'planning';
    case COMPOSING = 'composing';
    case REVIEWING = 'reviewing';
    case RENDERING = 'rendering';
    case COMPOSING_FINAL = 'composing_final';
    case DONE = 'done';
    case FAILED = 'failed';

    /**
     * Nhãn hiển thị, đặt CẠNH case để thêm trạng thái mới là buộc phải thêm nhãn —
     * `match` không có `default` nên thiếu một case là lỗi ngay lúc chạy dev, không
     * lọt ra production dưới dạng slug thô.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PLANNING => 'Planning',
            self::COMPOSING => 'Composing prompts',
            self::REVIEWING => 'Pending review',
            self::RENDERING => 'Rendering',
            self::COMPOSING_FINAL => 'Composing final',
            self::DONE => 'Done',
            self::FAILED => 'Failed',
        };
    }
}
