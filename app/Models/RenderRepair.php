<?php

declare(strict_types=1);

namespace App\Models;

use App\Video\Render\Enums\QaRepairStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RenderRepair extends Model
{
    use HasUuids;

    protected $fillable = [
        'qa_run_id',
        'parent_render_id',
        'repair_render_id',
        'repair_generation',
        'status',
        'repair_scope_id',
        'source_qa_report_hash',
        'parent_request_hash',
        'parent_artifact_hash',
        'repair_plan_json',
        'repair_request_hash',
    ];

    protected $casts = [
        'repair_generation' => 'integer',
        'status' => QaRepairStatus::class,
        'repair_plan_json' => 'array',
    ];

    public function qaRun()
    {
        return $this->belongsTo(RenderQaRun::class, 'qa_run_id');
    }

    public function parentRender()
    {
        return $this->belongsTo(VideoRender::class, 'parent_render_id');
    }

    public function repairRender()
    {
        return $this->belongsTo(VideoRender::class, 'repair_render_id');
    }
}
