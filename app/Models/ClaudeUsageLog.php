<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaudeUsageLog extends Model
{
    protected $fillable = [
        'admin_id', 'article_id', 'video_session_id',
        'title', 'source_url', 'action', 'total_tokens', 'total_cost_usd',
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function videoSession()
    {
        return $this->belongsTo(VideoSession::class, 'video_session_id');
    }
}
