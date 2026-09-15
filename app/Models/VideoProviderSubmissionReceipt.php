<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Bang chung append-only rang mot request DA roi khoi may va provider DA nhan job.
 *
 * Khac `VideoRender`/`VideoRenderAttempt`: hai bang kia la trang thai hien tai, bi
 * ghi de theo lease va theo generation. Bien lai thi khong.
 */
class VideoProviderSubmissionReceipt extends Model
{
    use HasUuids;

    protected $table = 'video_provider_submission_receipts';

    protected $fillable = [
        'render_id', 'attempt_id', 'attempt_no', 'claim_generation', 'claim_token',
        'provider', 'model', 'provider_job_id', 'provider_request_id',
        'response_json', 'observed_at',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'claim_generation' => 'integer',
        'response_json' => 'array',
        'observed_at' => 'immutable_datetime',
    ];

    /**
     * Append-only o tang model. DB moi chi chan XOA theo FK, nen mot doan code bat
     * ky van co the sua hoac xoa bien lai; hai chot nay chan duong Eloquent.
     *
     * Chung KHONG chan query builder tho — day la hang rao, khong phai buc tuong.
     */
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Bien lai submit la append-only: khong duoc sua.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Bien lai submit la append-only: khong duoc xoa.');
        });
    }

    public function render()
    {
        return $this->belongsTo(VideoRender::class, 'render_id');
    }

    public function attempt()
    {
        return $this->belongsTo(VideoRenderAttempt::class, 'attempt_id');
    }
}
