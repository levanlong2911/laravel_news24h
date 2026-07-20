<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class VideoShot extends Model {
    use HasUuids;
    // Shot là business object; prompt chỉ là compiled artifact (ADR v1.1)
    protected $fillable = ['session_id', 'beat', 'shot_code', 'shot_type', 'kind', 'spec_json',
        'compiled_prompt', 'negative_prompt', 'render_plan', 'status', 'review_note',
        'preview_path', 'artifact_path', 'cost_estimate', 'approved_at'];
    protected $casts = ['spec_json' => 'array', 'render_plan' => 'array', 'approved_at' => 'datetime'];
    public function session() { return $this->belongsTo(VideoSession::class, 'session_id'); }
}
