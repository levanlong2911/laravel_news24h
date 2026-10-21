<?php

namespace App\Models\PromptLibrary;

use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Du lieu dan xuat tu source_content cua revision, tao mot lan luc import va
 * khong bao gio sua. Ban goc luon la source_content.
 */
class PromptSection extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ai_prompt_sections';

    protected $fillable = [
        'revision_id', 'parent_id', 'section_key', 'heading', 'heading_level', 'kind', 'sort_order',
        'start_line', 'end_line', 'body', 'body_sha256', 'token_estimate',
    ];

    protected $casts = [
        'heading_level' => 'integer',
        'sort_order' => 'integer',
        'start_line' => 'integer',
        'end_line' => 'integer',
        'token_estimate' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $section): void {
            throw new PromptLibraryException("Section {$section->section_key} bat bien, hay import revision moi.");
        });

        static::deleting(function (self $section): void {
            throw new PromptLibraryException("Section {$section->section_key} khong xoa rieng duoc.");
        });
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PromptRevision::class, 'revision_id');
    }
}
