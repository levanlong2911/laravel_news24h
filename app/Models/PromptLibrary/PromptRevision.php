<?php

namespace App\Models\PromptLibrary;

use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Noi dung nguon (source_content, source_sha256) khong bao gio doi, ke ca o
 * draft: muon sua thi import revision moi. Sau draft chi con doi duoc trang
 * thai giua published va retired; revision da ra khoi draft khong xoa duoc, vi
 * run da pin no van phai doc lai duoc.
 */
class PromptRevision extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    public const UPDATED_AT = null;

    private const MUTABLE_AFTER_DRAFT = ['status', 'published_at'];

    private const TRANSITIONS = [
        self::DRAFT => [self::PUBLISHED],
        self::PUBLISHED => [self::RETIRED],
        self::RETIRED => [self::PUBLISHED],
    ];

    protected $table = 'ai_prompt_revisions';

    protected $fillable = [
        'document_id', 'version', 'status', 'format', 'source_content', 'source_sha256', 'source_filename',
        'source_commit', 'byte_size', 'metadata', 'created_by', 'published_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'byte_size' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            foreach (['document_id', 'version', 'format', 'source_content', 'source_sha256', 'byte_size'] as $field) {
                if ($revision->isDirty($field)) {
                    throw new PromptLibraryException("Revision {$revision->id}: {$field} bat bien, hay import revision moi.");
                }
            }

            $from = (string) $revision->getOriginal('status');

            if ($from !== self::DRAFT && array_diff(array_keys($revision->getDirty()), self::MUTABLE_AFTER_DRAFT) !== []) {
                throw new PromptLibraryException("Revision {$revision->id} da {$from}: chi doi duoc trang thai.");
            }

            if ($revision->isDirty('status') && ! in_array($revision->status, self::TRANSITIONS[$from] ?? [], true)) {
                throw new PromptLibraryException("Revision {$revision->id}: khong chuyen duoc {$from} -> {$revision->status}.");
            }
        });

        static::deleting(function (self $revision): void {
            if ($revision->getOriginal('status') !== self::DRAFT) {
                throw new PromptLibraryException("Revision {$revision->id} da ra khoi draft, khong xoa duoc.");
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PromptDocument::class, 'document_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PromptSection::class, 'revision_id')->orderBy('sort_order');
    }
}
