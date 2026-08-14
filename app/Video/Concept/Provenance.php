<?php

namespace App\Video\Concept;

/**
 * Cấp APECT, không phải cấp nội dung: `inspired` chỉ nói nhóm đó có insight
 * trong brief, KHÔNG chứng minh câu quyết định bắt nguồn từ insight ấy.
 */
enum Provenance: string
{
    case Inspired = 'inspired';
    case Invented = 'invented';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
