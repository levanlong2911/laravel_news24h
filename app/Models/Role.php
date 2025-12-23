<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;
    use HasUuid;

    /**
     * The primary key type for the model.
     *
     * @var string
     */
    protected $keyType = 'string'; // UUID là kiểu chuỗi

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false; // UUID không tự tăng

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
    ];

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }
}
