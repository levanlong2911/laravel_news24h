<?php

namespace App\Enums;

use App\Traits\EnumTrait;

/**
 * Trang thai vong doi cua mot VideoSession.
 *
 * Gia tri chuoi phai KHOP CHINH XAC voi du lieu da co trong cot
 * video_sessions.status (migration 2026_07_19_200000, string(20), default
 * 'draft') — doi gia tri o day la lam hong du lieu cu.
 *
 * Luong that (xem VideoSessionService):
 *   DRAFT      — mac dinh cua cot, chua dung toi trong code
 *   PLANNING   — session da tao (co code, co article_id), pipeline Claude
 *                dang chay NEN (video:build-plan --session=, §18.30);
 *                renderplan_json con null
 *   COMPOSING  — renderplan_json da co, cho Python bien dich prompt
 *   REVIEWING  — Python da day shot len, cho nguoi duyet
 *   RENDERING  — da co shot duoc queue de render
 *
 * Terminal states: DONE when the queue drains cleanly, FAILED as soon as any
 * shot fails (later shots may remain queued when a dependency chain stops) —
 * hoac khi pipeline Claude o PLANNING tu than that bai.
 */
enum VideoSessionStatus: string
{
    use EnumTrait;

    case DRAFT = 'draft';
    case PLANNING = 'planning';
    case COMPOSING = 'composing';
    case REVIEWING = 'reviewing';
    case RENDERING = 'rendering';
    case DONE = 'done';
    case FAILED = 'failed';

    /**
     * "Bài này đang có một lượt sản xuất chưa xong" — dùng ở HAI chỗ phải
     * đồng ý với nhau: chặn bấm hai lần (VideoSessionService::startVideoPlanning())
     * và disable nút trên danh sách bài viết (ArticleController::index()).
     * Tách hằng ở đây để không lệch danh sách giữa hai nơi.
     *
     * @return list<string>
     */
    public static function nonTerminalValues(): array
    {
        return [
            self::PLANNING->value,
            self::COMPOSING->value,
            self::REVIEWING->value,
            self::RENDERING->value,
        ];
    }
}
