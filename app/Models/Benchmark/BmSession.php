<?php

namespace App\Models\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BmSession extends Model
{
    protected $table = 'bm_sessions';

    protected $fillable = [
        'code', 'name', 'sprint', 'description',
        'control_session_id', 'git_commit',
    ];

    public function controlSession(): BelongsTo
    {
        return $this->belongsTo(BmSession::class, 'control_session_id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(BmRender::class, 'session_id');
    }

    /** Compute efficiency = score / (char_count / 1000) for each render in this session. */
    public function avgEfficiency(): float
    {
        return (float) $this->renders()
            ->join('bm_render_scores', 'bm_renders.id', '=', 'bm_render_scores.render_id')
            ->whereNotNull('bm_render_scores.overall')
            ->selectRaw('AVG(bm_render_scores.overall / (bm_renders.char_count / 1000)) as eff')
            ->value('eff');
    }
}
