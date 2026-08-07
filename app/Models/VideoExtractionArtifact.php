<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Bằng chứng của MỘT lần Truth Layer chạy. Ghi một chiều, không ai đọc lúc chạy.
 *
 * Không có `belongsTo(Article)` — cố ý. Artifact phải sống sót cả khi bài báo bị
 * xoá, vì khi đó nó là thứ duy nhất còn kể lại được chuyện gì đã xảy ra.
 */
class VideoExtractionArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'article_id', 'session_id', 'category',
        'model', 'instruction_version', 'profile_version',
        'tokens_in', 'tokens_out', 'latency_ms', 'cost_usd',
        'raw', 'candidate_graph', 'gatekeeper_report', 'diagnostics',
    ];

    protected $casts = [
        'candidate_graph' => 'array',
        'gatekeeper_report' => 'array',
        'diagnostics' => 'array',
        'cost_usd' => 'float',
    ];
}
