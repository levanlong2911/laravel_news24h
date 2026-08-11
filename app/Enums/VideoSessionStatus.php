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
 *   COMPOSING  — vua tao tu bai viet, cho Python bien dich prompt
 *   REVIEWING  — Python da day shot len, cho nguoi duyet
 *   RENDERING  — da co shot duoc queue de render
 *
 * Terminal states: DONE when the queue drains cleanly, FAILED as soon as any
 * shot fails (later shots may remain queued when a dependency chain stops).
 */
enum VideoSessionStatus: string
{
    use EnumTrait;

    case DRAFT = 'draft';
    case COMPOSING = 'composing';
    case REVIEWING = 'reviewing';
    case RENDERING = 'rendering';
    case DONE = 'done';
    case FAILED = 'failed';
}
