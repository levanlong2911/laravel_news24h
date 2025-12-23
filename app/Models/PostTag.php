<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostTag extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'post_tags';

    protected $fillable = [
        'post_id',
        'tag_id'
    ];

    public $timestamps = true;

}
