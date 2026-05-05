<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaudeUsage extends Model
{
    protected $fillable = ['admin_id', 'date', 'count'];

    protected $casts = ['date' => 'date', 'count' => 'integer'];
}
