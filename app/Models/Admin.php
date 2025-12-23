<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasUuid;

    protected $table = "admins";

    protected $keyType = "string";

    public $incrementing = false;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'domain', 'email_verified_at', 'remember_token'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];
    /** The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ["password", "remember_token"];

    /**
     * The attributes that should be visible for serialization.
     *
     * @var array<int, string>
     */
    protected $visible = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        "email_verified_at" => "datetime",
    ];


    // public function role()
    // {
    //     return $this->belongsTo(Role::class);
    // }


    public $timestamps = true;

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id'); // 'role_id' là khóa ngoại
    }

    public function posts()
    {
        return $this->hasMany(Post::class, 'author_id', 'id');
    }
}
