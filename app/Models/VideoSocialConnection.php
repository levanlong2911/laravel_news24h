<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An account a person connected to a platform. Its tokens and the tokens of its pages are stored encrypted and are
 * never put in a prompt, a manifest a person downloads, a hash or a log.
 */
class VideoSocialConnection extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'platform', 'label', 'external_account_id', 'access_token', 'refresh_token', 'token_expires_at',
        'scopes', 'accounts', 'status', 'last_error', 'connected_by',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'accounts' => 'encrypted:array',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'accounts'];

    public function channels()
    {
        return $this->hasMany(VideoSocialChannel::class, 'connection_id');
    }
}
