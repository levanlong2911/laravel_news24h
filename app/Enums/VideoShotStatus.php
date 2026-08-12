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
}
