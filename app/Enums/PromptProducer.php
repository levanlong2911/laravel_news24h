<?php

declare(strict_types=1);

namespace App\Enums;

enum PromptProducer: string
{
    case CANONICAL = 'canonical';
    case SKILL = 'skill';

    public function label(): string
    {
        return match ($this) {
            self::CANONICAL => 'Canonical (Python)',
            self::SKILL => 'Skill (Sonnet)',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::CANONICAL => 'Cần concept đã đóng băng · giữ đủ 8 hash lineage',
            self::SKILL => 'Đi thẳng từ brief Haiku · chỉ có prompt_sha256',
        };
    }
}
