<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasUuid;

    protected $table = "admins";

    protected $keyType = "string";

    public $incrementing = false;

    protected $fillable = ['name', 'email', 'password', 'role_id', 'domain_id', 'email_verified_at', 'remember_token'];
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

    public function domains()
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function claudeUsages()
    {
        return $this->hasMany(ClaudeUsage::class, 'admin_id');
    }

    public function claudeUsageToday(): int
    {
        return (int) ClaudeUsage::where('admin_id', $this->id)
            ->where('date', now()->toDateString())
            ->value('count');
    }

    public function claudeUsageThisMonth(): int
    {
        return (int) ClaudeUsage::where('admin_id', $this->id)
            ->whereYear('date', now()->year)
            ->whereMonth('date', now()->month)
            ->sum('count');
    }

    public function claudeUsageByDay(int $days = 30): \Illuminate\Support\Collection
    {
        return ClaudeUsage::where('admin_id', $this->id)
            ->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->orderBy('date')
            ->get(['date', 'count']);
    }

    public function incrementClaudeUsage(string $title = '', string $sourceUrl = '', string $action = 'send_to_claude'): void
    {
        DB::transaction(function () use ($title, $sourceUrl, $action) {
            DB::statement(
                'INSERT INTO claude_usages (admin_id, date, count, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE count = count + 1, updated_at = NOW()',
                [$this->id, now()->toDateString()]
            );

            ClaudeUsageLog::create([
                'admin_id'   => $this->id,
                'title'      => $title ?: null,
                'source_url' => $sourceUrl ?: null,
                'action'     => $action,
            ]);
        });
    }

    public function isAdmin(): bool
    {
        return $this->role && $this->role->name === 'admin';
    }

    public function isMember(): bool
    {
        return $this->role && $this->role->name === 'member';
    }


}
