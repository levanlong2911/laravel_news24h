<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class VideoSession extends Model {
    use HasUuids;
    protected $fillable = ['project_id', 'code', 'renderplan_json', 'status', 'cost_estimate_total', 'cost_actual'];
    protected $casts = ['renderplan_json' => 'array'];
    public function project() { return $this->belongsTo(VideoProject::class, 'project_id'); }
    public function shots() { return $this->hasMany(VideoShot::class, 'session_id')->orderBy('beat')->orderBy('shot_code'); }
}
