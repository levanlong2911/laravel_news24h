<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaudeUsageLog extends Model
{
    protected $fillable = ['admin_id', 'title', 'source_url', 'action'];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
