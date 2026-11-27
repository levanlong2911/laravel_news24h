<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoArtifact;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The package a person takes away to post by hand: the film, the cover, the text ready to copy, the metadata of the
 * posting and a manifest naming every source, revision and hash. It is made from what is stored — no model is called,
 * and making it again gives the same files.
 *
 * Exporting is something that happened, recorded as an event; it changes no state. A package exported is not a package posted.
 */
final class SocialExportService
{
    public const FORMAT = 'social-export-manifest-v1';

    public function __construct(
        private readonly SocialSourceService $sources,
        private readonly SocialPackageService $packages,
    ) {}

    /**
     * @return array{path: string, name: string, manifest: array<string, mixed>} the ZIP, for the caller to send and delete, and the manifest inside it
     *
     * @throws RuntimeException when the revision is not approved, the film approved now is not the film of the package, or a file of the package is gone or changed
     */
    public function export(string $projectId, string $packageId, ?string $actorId): array
    {
        $package = $this->packages->owned($projectId, $packageId);
        $decision = SocialApprovalService::decisionOf($package);

        if (! SocialApprovalService::approved($package) || $decision === null) {
            throw new RuntimeException('Chỉ xuất được bản đã duyệt — duyệt gói trước.');
        }

        $read = $this->sources->read($projectId);

        if (! hash_equals((string) $package->source_hash, (string) $read['snapshot']['hash'])) {
            throw new RuntimeException('Phim đã duyệt của dự án đã đổi: gói này tham chiếu bản phim trước và được giữ nguyên trong lịch sử. Tạo revision cho phim hiện hành, duyệt rồi xuất bản đó.');
        }

        $document = (array) $package->document_json;
        $source = (array) $package->source_json;
        $rules = (array) $source['policy']['rules'];
        $cover = is_array($document['artwork'] ?? null) ? VideoArtifact::query()->whereKey($document['artwork']['artifact_id'])->where('project_id', $projectId)->first() : null;
        $coverPath = $cover === null ? null : SocialArtworkService::path($cover);

        if (is_array($document['artwork'] ?? null) && ($cover === null || $coverPath === null || ! hash_equals((string) $document['artwork']['sha256'], (string) hash_file('sha256', $coverPath)))) {
            throw new RuntimeException('File cover của gói không còn hoặc đã đổi nội dung — không xuất.');
        }

        $name = 'social-'.Str::slug((string) $source['channel']['display_name']).'-'.$package->format.'-'.$package->language.'-r'.$package->revision;
        $video = 'video/'.$name.'.'.($source['film']['media']['container'] ?: 'mp4');
        $metadata = [
            'platform' => $document['target']['platform'],
            'format' => $document['target']['format'],
            'format_label' => $rules['label'],
            'language' => $document['target']['language'],
            'channel' => ['display_name' => $source['channel']['display_name'], 'url' => $source['channel']['url'] ?? null, 'external_id' => $document['target']['external_id']],
            'title' => $document['rendered']['title'],
            'text' => $document['rendered']['text'],
            'tags' => $document['rendered']['tags'],
            'hashtags' => $document['content']['hashtags'],
            'link' => $document['content']['link'],
            'privacy' => $document['metadata']['privacy'],
            'made_for_kids' => $document['metadata']['made_for_kids'],
            'disclosure' => [
                'sentence_in_text' => $document['content']['disclosure_text'],
                'synthetic_media_declaration' => $document['disclosure']['synthetic_media'],
                'declared_through_api' => (bool) $rules['synthetic_media']['api'],
                'note' => $rules['synthetic_media']['note'],
            ],
            'cover' => $cover === null ? null : ['file' => 'cover/cover.jpg', 'width' => $document['artwork']['width'], 'height' => $document['artwork']['height'], 'set_through_api' => (bool) $rules['cover']['api'], 'note' => $rules['cover']['note']],
            'video' => ['file' => $video] + array_intersect_key((array) $source['film']['media'], array_flip(['width', 'height', 'fps', 'duration_ms', 'bytes', 'has_audio'])),
        ];
        $files = [
            'post.txt' => self::text($metadata),
            'metadata.json' => self::json($metadata),
        ];
        $manifest = [
            'format' => self::FORMAT,
            'project_id' => $projectId,
            'package' => [
                'group_id' => (string) $package->package_group_id,
                'revision_id' => (string) $package->id,
                'revision' => (int) $package->revision,
                'package_hash' => (string) $package->package_hash,
                'approved_by_decision' => (string) $decision->id,
                'approved_at' => $decision->created_at?->toIso8601String(),
            ],
            'source' => ['hash' => (string) $package->source_hash, 'pins' => $source['pins']],
            'policy' => array_intersect_key((array) $source['policy'], array_flip(['phase_id', 'phase_revision', 'policy_hash', 'checked_on'])),
            'channel_hash' => (string) $source['channel_hash'],
            'content_origin' => $package->ai_stage_id === null
                ? ['written_by' => 'person', 'ai_stage_id' => null]
                : ['written_by' => 'model, then edited and approved by a person', 'ai_stage_id' => (string) $package->ai_stage_id],
            'files' => [
                ['path' => $video, 'sha256' => (string) $read['snapshot']['film']['sha256'], 'bytes' => (int) filesize($read['path'])],
                ...($coverPath === null ? [] : [['path' => 'cover/cover.jpg', 'sha256' => (string) $document['artwork']['sha256'], 'bytes' => (int) filesize($coverPath)]]),
                ...array_map(static fn (string $path, string $content): array => ['path' => $path, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)], array_keys($files), $files),
            ],
            'note' => 'Exporting this package does not post it. A posting made by hand is recorded when a person reports its URL.',
        ];
        $path = $this->archive($name, [$video => $read['path']] + ($coverPath === null ? [] : ['cover/cover.jpg' => $coverPath]), $files + ['manifest.json' => self::json($manifest)]);
        VideoSocialEvent::record($projectId, 'package_exported', (string) $package->id, null, $actorId, ['manifest' => $manifest]);

        return ['path' => $path, 'name' => $name.'.zip', 'manifest' => $manifest];
    }

    /**
     * @param  array<string, string>  $stored  files copied from the disk, by their name in the archive
     * @param  array<string, string>  $written  files written from text, by their name in the archive
     * @return string the path of the archive
     */
    private function archive(string $name, array $stored, array $written): string
    {
        $directory = rtrim((string) config('video.veo.compose_tmp_dir'), '/\\').DIRECTORY_SEPARATOR.'social-export';

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Không tạo được thư mục tạm để xuất gói.');
        }

        // An archive a broken download never got to delete is swept on a later export.
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.zip') ?: [] as $stale) {
            if ((int) @filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }

        $path = $directory.DIRECTORY_SEPARATOR.$name.'-'.Str::uuid().'.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không tạo được file ZIP.');
        }

        foreach ($stored as $entry => $file) {
            // A film and a JPEG are compressed already: they are stored as they are, so the bytes in the archive are the bytes that were hashed.
            if (! $zip->addFile($file, $entry) || ! $zip->setCompressionName($entry, ZipArchive::CM_STORE)) {
                $zip->close();
                @unlink($path);

                throw new RuntimeException("Không đưa được {$entry} vào ZIP.");
            }
        }

        foreach ($written as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        if (! $zip->close()) {
            @unlink($path);

            throw new RuntimeException('Không ghi xong file ZIP.');
        }

        return $path;
    }

    /** @param array<string, mixed> $metadata */
    private static function text(array $metadata): string
    {
        $lines = [
            "KÊNH: {$metadata['channel']['display_name']} ({$metadata['format_label']}, {$metadata['language']})",
            '',
            '===== TIÊU ĐỀ =====',
            $metadata['title'] ?? '(định dạng này không dùng tiêu đề, hoặc gói không có tiêu đề)',
            '',
            '===== NỘI DUNG ĐĂNG (dán nguyên văn) =====',
            $metadata['text'],
            '',
            '===== TỪ KHÓA / TAGS =====',
            $metadata['tags'] === [] ? '(không có)' : implode(', ', $metadata['tags']),
            '',
            '===== KHI ĐĂNG =====',
            'Chế độ hiển thị: '.($metadata['privacy'] ?? '(nền tảng không có lựa chọn này)'),
            'Dành cho trẻ em: '.match ($metadata['made_for_kids']) {
                true => 'có', false => 'không', default => '(nền tảng không có lựa chọn này)'
            },
            'Khai báo nội dung tổng hợp/AI của nền tảng: '.($metadata['disclosure']['synthetic_media_declaration'] ? 'CÓ — bật khi đăng' : 'không'),
            'Cover: '.($metadata['cover'] === null ? '(gói không có cover)' : 'cover/cover.jpg — đặt làm ảnh bìa khi đăng'),
            'Video: '.$metadata['video']['file'],
        ];

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
