<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class VideoProject extends Model {
    use HasUuids;
    protected $fillable = ['name', 'subject_id', 'design_ref'];
    public function sessions() { return $this->hasMany(VideoSession::class, 'project_id'); }
}
