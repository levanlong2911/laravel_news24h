<?php

namespace App\Services\PromptLibrary\Import;

use App\Models\PromptLibrary\PromptDocument;
use App\Models\PromptLibrary\PromptRevision;
use App\Models\PromptLibrary\PromptSection;
use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Support\Facades\DB;

/**
 * File trong git la nguon bien tap; DB la ban da publish de runtime doc.
 *
 *   format "text"      ca file la MOT section "body", than = tung byte cua file
 *                      (khong chuan hoa xuong dong) -> dung cho prompt dang chay
 *                      nhu storyboard.txt, de hash DB trung hash file.
 *   format "markdown"  tach cay section bang MarkdownSectionParser tren ban da
 *                      chuan hoa LF/bo BOM; source_content van giu nguyen ban goc.
 *
 * Cung document + cung source_sha256 -> tra revision da co (idempotent).
 * Cung version nhung khac noi dung -> tu choi; doi noi dung phai tang version.
 */
final class PromptImporter
{
    public const TEXT = 'text';

    public const MARKDOWN = 'markdown';

    /** Nhieu file mot document: source_content la JSON {"files": {ten: noi dung}}, moi file mot section. */
    public const FILES = 'files';

    /**
     * Noi dung chuan cua mot bo file cho format "files". Thu tu file giu nguyen
     * (la thu tu section), nen cung bo file cung thu tu thi cung sha256.
     *
     * @param  array<string, string>  $files
     */
    public static function filesContent(array $files): string
    {
        return json_encode(['files' => $files], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function __construct(private readonly MarkdownSectionParser $parser) {}

    /**
     * @param  array{name?: string, type?: string, description?: ?string, filename?: ?string, commit?: ?string, created_by?: ?string}  $meta
     * @return array{0: PromptRevision, 1: bool} [revision, created]
     */
    public function import(string $content, string $documentKey, string $version, string $format, array $meta = []): array
    {
        if (! in_array($format, [self::TEXT, self::MARKDOWN, self::FILES], true)) {
            throw new PromptLibraryException("Format khong ho tro: {$format}");
        }

        if ($content === '') {
            throw new PromptLibraryException('File rong.');
        }

        [$namespace, $key] = PromptDocument::splitFullKey($documentKey);
        $sha = hash('sha256', $content);
        $document = PromptDocument::findByFullKey($documentKey);

        if ($document !== null) {
            $same = $document->revisions()->where('source_sha256', $sha)->first();

            if ($same !== null) {
                return [$same, false];
            }

            if ($document->revisions()->where('version', $version)->exists()) {
                throw new PromptLibraryException("{$documentKey} {$version} da ton tai voi noi dung khac; hay tang version.");
            }
        }

        $sections = $this->sections($content, $format);
        $this->assertRequired($documentKey, $sections);

        return DB::transaction(function () use ($document, $namespace, $key, $meta, $version, $format, $content, $sha, $sections): array {
            $document ??= PromptDocument::create([
                'namespace' => $namespace,
                'key' => $key,
                'name' => $meta['name'] ?? $namespace.'.'.$key,
                'document_type' => $meta['type'] ?? 'workflow',
                'description' => $meta['description'] ?? null,
            ]);

            $revision = PromptRevision::create([
                'document_id' => $document->id,
                'version' => $version,
                'status' => PromptRevision::DRAFT,
                'format' => $format,
                'source_content' => $content,
                'source_sha256' => $sha,
                'source_filename' => $meta['filename'] ?? null,
                'source_commit' => $meta['commit'] ?? null,
                'byte_size' => strlen($content),
                'created_by' => $meta['created_by'] ?? null,
            ]);

            $ids = [];

            foreach ($sections as $order => $section) {
                $ids[$section->key] = PromptSection::create([
                    'revision_id' => $revision->id,
                    'parent_id' => $section->parentKey === null ? null : ($ids[$section->parentKey] ?? null),
                    'section_key' => $section->key,
                    'heading' => $section->heading === null ? null : mb_substr($section->heading, 0, 255),
                    'heading_level' => $section->level,
                    'kind' => $section->kind,
                    'sort_order' => $order,
                    'start_line' => $section->startLine,
                    'end_line' => $section->endLine,
                    'body' => $section->body,
                    'body_sha256' => hash('sha256', $section->body),
                    'token_estimate' => (int) ceil(strlen($section->body) / 4),
                ])->id;
            }

            return [$revision, true];
        });
    }

    /** @return list<ParsedSection> */
    public function sections(string $content, string $format): array
    {
        if ($format === self::TEXT) {
            return [new ParsedSection('body', null, null, 0, 'document', 1, max(1, substr_count($content, "\n")), $content)];
        }

        if ($format === self::FILES) {
            $files = json_decode($content, true, 512, JSON_THROW_ON_ERROR)['files'] ?? null;

            if (! is_array($files) || $files === [] || array_filter($files, 'is_string') !== $files) {
                throw new PromptLibraryException('Format files can {"files": {ten: noi dung}} khong rong.');
            }

            $sections = [];

            foreach ($files as $name => $body) {
                $sections[] = new ParsedSection((string) $name, null, (string) $name, 0, 'file', 1, max(1, substr_count($body, "\n")), $body);
            }

            return $sections;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        if (str_starts_with($normalized, "\xEF\xBB\xBF")) {
            $normalized = substr($normalized, 3);
        }

        return $this->parser->parse($normalized);
    }

    /** @param list<ParsedSection> $sections */
    private function assertRequired(string $documentKey, array $sections): void
    {
        $required = (array) config("prompt_library.document_requirements.{$documentKey}", []);
        $missing = array_values(array_diff($required, array_map(fn (ParsedSection $s): string => $s->key, $sections)));

        if ($missing !== []) {
            throw new PromptLibraryException("{$documentKey} thieu section bat buoc: ".implode(', ', $missing));
        }
    }
}
