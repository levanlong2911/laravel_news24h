<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryOutputField extends Model
{
    use HasUuids;

    protected $fillable = [
        'category_id', 'field_key', 'field_type',
        'is_required', 'description', 'example_value',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** Build JSON schema string để inject vào prompt */
    public static function buildSchemaBlock(string $categoryId): string
    {
        $fields = self::where('category_id', $categoryId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($fields->isEmpty()) {
            // Default schema nếu chưa cấu hình
            return "{\n  \"title\": \"...\",\n  \"meta_description\": \"...\",\n  \"content\": \"...\",\n  \"faq\": []\n}";
        }

        $lines = $fields->map(function ($f) {
            $hint    = $f->description   ? " // {$f->description}"   : '';
            $example = $f->example_value ? " e.g.: {$f->example_value}" : '';
            $req     = $f->is_required   ? ''                        : ' (optional)';
            return "  \"{$f->field_key}\": \"...\"{$req}{$hint}{$example}";
        })->implode(",\n");

        return "{\n{$lines}\n}";
    }
}
