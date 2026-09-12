<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Models;

use App\Models\VideoRender;
use App\Models\VideoShot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class VideoShotRepair extends Model
{
    use HasUuids;

    protected $fillable = [
        'video_shot_id',
        'parent_render_execution_id',
        'child_render_execution_id',
        'qa_report_hash',
        'repair_plan_hash',
        'repair_index',
        'failure_signature',
        'mode',
        'status',
        'repair_plan_json',
    ];

    protected $casts = [
        'repair_index' => 'integer',
        'repair_plan_json' => 'array',
    ];

    public function shot()
    {
        return $this->belongsTo(VideoShot::class, 'video_shot_id');
    }

    public function parentRender()
    {
        return $this->belongsTo(VideoRender::class, 'parent_render_execution_id');
    }

    public function childRender()
    {
        return $this->belongsTo(VideoRender::class, 'child_render_execution_id');
    }
}
