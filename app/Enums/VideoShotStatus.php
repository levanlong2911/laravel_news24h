<?php

namespace App\Enums;

use App\Traits\EnumTrait;

/**
 * Trang thai cua mot VideoShot — approval gate (ADR v1.1) + claim/lease (2026-08-12):
 *
 *   DRAFT -> APPROVED -> QUEUED -> CLAIMED -> RENDERING -> RENDERED | FAILED
 *         -> NEEDS_REVISION                            (het lease -> ve QUEUED)
 *         -> REJECTED
 *
 * SUPERSEDED (them 2026-08-13): shot thuoc mot ban compose CU, bi thay the
 * boi mot lan Python compose lai (storeFromPython() tang plan_revision) va
 * khong con xuat hien trong payload moi. Chi ap dung cho shot dang o trang
 * thai AN TOAN de bo qua (draft/approved/needs_revision/rejected/queued) —
 * claimed/rendering/rendered/failed khong bao gio bi supersede am tham, xem
 * VideoSessionService::storeFromPython().
 *
 * KHONG co duong nao tu DRAFT thang len QUEUED: chi shot APPROVED moi vao
 * hang doi (VideoShotRepository::queueApprovedForSession). Do la ca ly do
 * approval gate ton tai — khong co duong render nao bo qua duyet.
 *
 * CLAIMED/RENDERING them boi migration 2026_08_12_100000 de chan claim trung:
 * mot worker UPDATE...LIMIT giu shot bang claim_token rieng cho tung batch,
 * KHONG con duong GET queued/render truc tiep nhu truoc — xem
 * VideoShotRepository::claimForSession().
 *
 * Gia tri chuoi phai KHOP CHINH XAC voi du lieu da co trong cot
 * video_shots.status (migration 2026_07_19_200000, string(20), default 'draft').
 */
enum VideoShotStatus: string
{
    use EnumTrait;

    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case NEEDS_REVISION = 'needs_revision';
    case REJECTED = 'rejected';
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case RENDERING = 'rendering';
    case RENDERED = 'rendered';
    case FAILED = 'failed';
    case SUPERSEDED = 'superseded';

    /**
     * Nhan hien thi. Bang RIENG, khong dung chung voi VideoSessionStatus: ba chuoi
     * `draft`/`rendering`/`failed` trung ten o ca hai enum nhung khac nghia —
     * session `rendering` la "dang co shot chay", shot `rendering` la "chinh clip
     * nay dang dung".
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::APPROVED => 'Approved',
            self::NEEDS_REVISION => 'Needs revision',
            self::REJECTED => 'Rejected',
            self::QUEUED => 'Queued',
            self::CLAIMED => 'Claimed',
            self::RENDERING => 'Rendering',
            self::RENDERED => 'Rendered',
            self::FAILED => 'Failed',
            self::SUPERSEDED => 'Superseded',
        };
    }

    /**
     * Con o TRUOC hang doi render — Duyet/Sua/Tu choi con hop nghia. Sau khi
     * QUEUED, quyet dinh duyet lai la vo nghia (da vao hang doi/render roi)
     * va nguy hiem (dua mot shot da RENDERED (ton tien that) quay lai
     * APPROVED se cho phep render lai lan hai). Dung chung cho
     * VideoShotRepository::approveByIds()/updateReviewStatus() — mot noi
     * duy nhat dinh nghia "con duyet duoc", khong de tung noi ghi tu do.
     *
     * @return list<string>
     */
    public static function reviewableValues(): array
    {
        return [
            self::DRAFT->value,
            self::APPROVED->value,
            self::NEEDS_REVISION->value,
            self::REJECTED->value,
        ];
    }

    /** @return list<string> */
    public static function safeSupersedableValues(): array
    {
        return [
            self::DRAFT->value,
            self::APPROVED->value,
            self::NEEDS_REVISION->value,
            self::REJECTED->value,
            self::QUEUED->value,
        ];
    }
}
