<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoPlanningStage;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class YachtProxyService
{
    public const EXECUTION = 'proxy-3d-v1';

    public const REVIEW_EXECUTION = 'proxy-3d-review-v1';

    public const KINDS = ['clay', 'parts'];

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly YachtDesignService $design,
        private readonly FilesystemFactory $storage,
    ) {}

    /** @return array<string, mixed> */
    public function payload(string $projectId): array
    {
        $panel = $this->design->panel($projectId);

        if (! $panel['current'] || ($panel['check']['verdict'] ?? null) !== YachtDesignCheck::PASS || ! is_string($panel['stage_id'])) {
            throw new RuntimeException('Chưa có bản thiết kế Phase 3 hiện hành đạt kiểm tự động.');
        }

        $stage = VideoPlanningStage::query()->whereKey($panel['stage_id'])->where('project_id', $projectId)->first();
        $canonical = $stage?->output_json['design']['data']['canonical'] ?? null;
        $hash = is_array($canonical) ? ($canonical['hash'] ?? null) : null;

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value || ! is_string($hash) || $hash === '') {
            throw new RuntimeException('Bản thiết kế nguồn không có dữ liệu canonical hoặc thiếu hash.');
        }

        $config = (array) config('video.superyacht.proxy');
        $approved = (array) config('video.superyacht.model_sheet.approved');
        $data = [
            'builder' => (string) $config['builder'],
            'builder_sha256' => (string) hash_file('sha256', public_path('assets/js/yacht-proxy.js')),
            'design_stage_id' => (string) $stage->id,
            'design_canonical_hash' => $hash,
            'canonical' => array_intersect_key($canonical, array_flip((array) $config['canonical_keys'])),
            'features' => array_values((array) ($approved[$hash]['proxy'] ?? [])),
            'views' => (array) config('video.superyacht.model_sheet.frames'),
            'assumptions' => (array) $config['assumptions'],
            'tolerances' => (array) $config['tolerances'],
            'fill' => (float) $config['fill'],
        ];
        $data['input_sha256'] = hash('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $data;
    }

    /** @return array<string, mixed> */
    public function page(string $projectId): array
    {
        $design = $this->design->panel($projectId);

        try {
            [$payload, $error] = [$this->payload($projectId), null];
        } catch (RuntimeException $e) {
            [$payload, $error] = [null, $e->getMessage()];
        }

        $approved = $payload === null ? null : $this->approvedStage($projectId, (string) $payload['input_sha256']);
        $revisions = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::PROXY_3D->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->map(fn (VideoPlanningStage $stage): array => [
                'id' => (string) $stage->id,
                'revision' => (int) $stage->planning_revision,
                'created_at' => $stage->finished_at ?? $stage->created_at,
                'created_by' => (string) ($stage->output_json['created_by'] ?? ''),
                'current' => $payload !== null && ($stage->input_json['input_sha256'] ?? null) === $payload['input_sha256'],
                'approved' => $approved !== null && (string) $approved->id === (string) $stage->id,
                'pass' => ($stage->output_json['verdict']['pass'] ?? false) === true,
                'failed' => array_values((array) ($stage->output_json['verdict']['failed'] ?? [])),
                'artifacts' => array_values((array) ($stage->output_json['artifacts'] ?? [])),
            ])
            ->all();

        return [
            'design_name' => $design['name'],
            'design_stage_id' => $design['stage_id'],
            'payload' => $payload,
            'error' => $error,
            'approved_id' => $approved === null ? null : (string) $approved->id,
            'revisions' => $revisions,
        ];
    }

    /**
     * @param  array{input_sha256: string, report: string, cameras: string}  $data
     * @param  array<string, mixed>  $images
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function save(string $projectId, string $creator, array $data, array $images, ?UploadedFile $glb): array
    {
        try {
            $payload = $this->payload($projectId);
            [$report, $cameras, $files] = $this->verified($payload, $data, $images, $glb);
            $verdict = $this->verdict($payload, $report);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::PROXY_3D, [
            'execution' => self::EXECUTION,
            'design_stage_id' => $payload['design_stage_id'],
            'design_canonical_hash' => $payload['design_canonical_hash'],
            'input_sha256' => $payload['input_sha256'],
            'builder' => $payload['builder'],
            'builder_sha256' => $payload['builder_sha256'],
            'attempt' => (string) Str::uuid(),
        ]);

        if ($token === null) {
            return [null, 'Không ghi được revision proxy ('.$reason.').'];
        }

        $disk = (string) config('video.openai_image.disk');
        $written = [];

        try {
            $artifacts = [];

            foreach ($files as $key => $file) {
                $path = $projectId.'/proxy/'.$stage->id.'/'.$key.'.'.$file['extension'];
                $this->storage->disk($disk)->put($path, $file['bytes']);
                $written[] = $path;
                $artifact = VideoArtifact::create([
                    'project_id' => $projectId,
                    'artifact_type' => $file['type'],
                    'role' => 'proxy_'.$file['kind'],
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'mime_type' => $file['mime'],
                    'file_size' => strlen($file['bytes']),
                    'sha256' => hash('sha256', $file['bytes']),
                    'width' => $file['width'],
                    'height' => $file['height'],
                    'metadata_json' => ['proxy_stage_id' => (string) $stage->id, 'view_id' => $file['view'], 'kind' => $file['kind']],
                ]);
                $artifacts[] = [
                    'key' => $key,
                    'view_id' => $file['view'],
                    'kind' => $file['kind'],
                    'artifact_id' => (string) $artifact->id,
                    'sha256' => (string) $artifact->sha256,
                    'width' => $file['width'],
                    'height' => $file['height'],
                ];
            }

            $saved = $this->stageStore->finishSucceeded((string) $stage->id, $token, '', [
                'revision' => (int) $stage->planning_revision,
                'created_by' => $creator,
                'cameras' => $cameras,
                'report' => $report,
                'verdict' => $verdict,
                'assumptions' => $payload['assumptions'],
                'artifacts' => $artifacts,
            ]);

            if (! $saved) {
                throw new RuntimeException('lượt lưu bị giành mất');
            }
        } catch (Throwable $e) {
            $this->stageStore->finishFailed((string) $stage->id, $token, $e->getMessage());
            VideoArtifact::query()->whereIn('storage_path', $written)->where('storage_disk', $disk)->delete();
            $this->storage->disk($disk)->delete($written);

            return [null, 'Không lưu được revision proxy ('.$e->getMessage().').'];
        }

        return [VideoPlanningStage::query()->find($stage->id), 'saved'];
    }

    /** @return array{0: bool, 1: string} */
    public function approve(string $projectId, string $stageId, ?string $adminId): array
    {
        try {
            $payload = $this->payload($projectId);
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::PROXY_3D->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        if ($stage === null) {
            return [false, 'Revision proxy không tồn tại trong dự án này.'];
        }

        if (($stage->input_json['input_sha256'] ?? null) !== $payload['input_sha256']) {
            return [false, 'Revision này dựng từ dữ liệu thiết kế hoặc cấu hình cũ — lưu một revision mới từ trang hiện tại rồi duyệt.'];
        }

        if (($stage->output_json['verdict']['pass'] ?? false) !== true) {
            return [false, 'Revision này chưa đạt hết bảng kiểm do server tính lại ('.implode(', ', (array) ($stage->output_json['verdict']['failed'] ?? [])).') — không duyệt được.'];
        }

        [$review, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::PROXY_3D_REVIEW, [
            'execution' => self::REVIEW_EXECUTION,
            'proxy_stage_id' => (string) $stage->id,
            'input_sha256' => $payload['input_sha256'],
        ], true);

        if ($token === null) {
            return [false, 'Không ghi được lượt duyệt proxy ('.$reason.').'];
        }

        $saved = $this->stageStore->finishSucceeded((string) $review->id, $token, '', ['approved_by' => $adminId, 'approved_at' => now()->toIso8601String()]);

        return $saved ? [true, 'approved'] : [false, 'Lượt duyệt proxy bị giành mất khi lưu — tải lại trang.'];
    }

    public function approvedFor(string $projectId): ?VideoPlanningStage
    {
        try {
            $payload = $this->payload($projectId);
        } catch (RuntimeException) {
            return null;
        }

        return $this->approvedStage($projectId, (string) $payload['input_sha256']);
    }

    private function approvedStage(string $projectId, string $inputSha): ?VideoPlanningStage
    {
        $review = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::PROXY_3D_REVIEW->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(fn (VideoPlanningStage $row): bool => ($row->input_json['input_sha256'] ?? null) === $inputSha);

        return $review === null ? null : VideoPlanningStage::query()
            ->whereKey($review->input_json['proxy_stage_id'] ?? null)
            ->where('project_id', $projectId)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{input_sha256: string, report: string, cameras: string}  $data
     * @param  array<string, mixed>  $images
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, array<string, mixed>>}
     */
    private function verified(array $payload, array $data, array $images, ?UploadedFile $glb): array
    {
        if (! hash_equals((string) $payload['input_sha256'], (string) $data['input_sha256'])) {
            throw new RuntimeException('Trang proxy đã cũ so với bản thiết kế hoặc cấu hình hiện hành — tải lại trang rồi lưu lại.');
        }

        $report = json_decode((string) $data['report'], true);
        $cameras = json_decode((string) $data['cameras'], true);

        if (! is_array($report) || ($report['input_sha256'] ?? null) !== $payload['input_sha256'] || ! is_array($report['checks'] ?? null) || ! is_bool($report['pass'] ?? null)) {
            throw new RuntimeException('Báo cáo kiểm không khớp dữ liệu của trang — tải lại trang rồi lưu lại.');
        }

        $views = (array) $payload['views'];

        if (! is_array($cameras) || array_column($cameras, 'view') !== array_keys($views)) {
            throw new RuntimeException('Thông số camera gửi lên không đủ '.count($views).' góc theo đúng thứ tự.');
        }

        $maxPng = (int) config('video.superyacht.proxy.max_png_bytes');
        $files = [];

        foreach ($views as $view => $frame) {
            foreach (self::KINDS as $kind) {
                $key = $view.'_'.$kind;
                $file = $images[$key] ?? null;

                if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > $maxPng) {
                    throw new RuntimeException("Thiếu hoặc lỗi ảnh {$key}.");
                }

                $bytes = (string) file_get_contents($file->getRealPath());
                $info = @getimagesizefromstring($bytes);

                if ($info === false || ($info['mime'] ?? null) !== 'image/png' || $info[0] !== (int) $frame['width'] || $info[1] !== (int) $frame['height']) {
                    throw new RuntimeException("Ảnh {$key} phải là PNG {$frame['width']}×{$frame['height']}.");
                }

                $files[$key] = ['bytes' => $bytes, 'view' => $view, 'kind' => $kind, 'type' => 'image', 'mime' => 'image/png', 'extension' => 'png', 'width' => $info[0], 'height' => $info[1]];
            }
        }

        $glbBytes = $glb instanceof UploadedFile && $glb->isValid() && $glb->getSize() <= (int) config('video.superyacht.proxy.max_glb_bytes')
            ? (string) file_get_contents($glb->getRealPath())
            : '';

        if (($error = self::glbError($glbBytes)) !== null) {
            throw new RuntimeException('File proxy.glb không hợp lệ: '.$error.'.');
        }

        $files['proxy'] = ['bytes' => $glbBytes, 'view' => null, 'kind' => 'glb', 'type' => 'model', 'mime' => 'model/gltf-binary', 'extension' => 'glb', 'width' => null, 'height' => null];

        return [$report, $cameras, $files];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, optional: bool, min: ?float, max: ?float}>
     */
    public function requiredChecks(array $payload): array
    {
        $canonical = (array) $payload['canonical'];
        $tolerance = static fn (string $name): float => (float) ($payload['tolerances'][$name] ?? throw new RuntimeException("Cấu hình proxy thiếu sai số {$name}."));
        $assumption = static function (string $code) use ($payload): float {
            $value = $payload['assumptions'][$code]['value'] ?? throw new RuntimeException("Cấu hình proxy thiếu giả định {$code}.");

            return (float) (is_array($value) ? $value[0] : $value);
        };
        $number = static fn (mixed $value): bool => is_int($value) || is_float($value);
        $decks = count(array_filter((array) ($canonical['decks'] ?? []), static fn (mixed $deck): bool => is_array($deck) && $number($deck['floor_z_m'] ?? null) && $number($deck['start_x'] ?? null) && $number($deck['end_x'] ?? null)));
        $required = [];
        $add = static function (string $key, ?float $min, ?float $max, bool $optional = false) use (&$required): void {
            $required[$key] ??= ['key' => $key, 'optional' => $optional, 'min' => $min, 'max' => $max];
        };

        $add('profile', 0.0, $tolerance('profile_m'));
        $add('plan', 0.0, $tolerance('plan_m'));
        $add('decks', (float) $decks, (float) $decks);
        $crest = $canonical['landmarks']['crest'] ?? null;

        if (is_array($crest) && $number($crest['x'] ?? null) && $number($crest['z'] ?? null)) {
            $add('crest_x', 0.0, $tolerance('crest_x'));
            $add('crest_z', 0.0, $tolerance('crest_z_m'));
        }

        if (is_numeric($canonical['dimensions']['beam']['value'] ?? null)) {
            $add('ratio', 0.0, $tolerance('ratio'));
        }

        if (isset($payload['views']['MS6'])) {
            $add('ms6', 1.0, 1.0);
        }

        $features = (array) $payload['features'];

        foreach ($features === [] ? [['type' => 'pocket']] : $features as $feature) {
            $optional = ($feature['optional'] ?? false) === true;

            match ($feature['type'] ?? null) {
                'slot' => $add('slot', 0.0, $tolerance('detail_m')),
                'disc' => array_map(static fn (string $ref) => $add('disc:'.$ref, 0.0, $tolerance('landmark_m')), (array) $feature['at']),
                'pocket' => $add('pockets', $tolerance('pocket_coverage'), null),
                'ribs' => [$add('rib_long', 0.0, $tolerance('rib_longitudinal_deg')), $add('rib_trans', $tolerance('rib_transverse_deg'), 90.0), $add('rib_spacing', 1.0, $tolerance('rib_spacing_factor'))],
                'window' => isset($feature['at'])
                    ? [$add('window:'.$feature['id'], 0.0, $tolerance('landmark_m'), $optional), $add('recess:'.$feature['id'], 0.0, $assumption('G10'), $optional)]
                    : [$add('window:'.$feature['id'], 0.0, 0.0, $optional), $add('recess:'.$feature['id'], 0.0, $tolerance('detail_dim_m'), $optional)],
                'bulwark', 'coaming', 'edge', 'rails' => $add('detail:'.$feature['id'], 0.0, $tolerance('detail_dim_m')),
                default => null,
            };
        }

        return array_values($required);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $report
     * @return array{pass: bool, failed: list<string>, warned: list<string>}
     */
    private function verdict(array $payload, array $report): array
    {
        $checks = [];

        foreach ((array) $report['checks'] as $check) {
            $key = is_array($check) ? ($check['key'] ?? null) : null;

            if (! is_string($key) || isset($checks[$key])) {
                throw new RuntimeException('Báo cáo kiểm có mục thiếu khoá hoặc trùng khoá — tải lại trang rồi lưu lại.');
            }

            $checks[$key] = $check;
        }

        $failed = [];
        $warned = [];

        foreach ($this->requiredChecks($payload) as $required) {
            $check = $checks[$required['key']] ?? throw new RuntimeException("Báo cáo kiểm thiếu phép đo bắt buộc {$required['key']} — tải lại trang rồi lưu lại.");
            $value = $check['value'] ?? null;
            ['min' => $min, 'max' => $max] = $required;

            $pass = (is_int($value) || is_float($value)) && is_finite((float) $value)
                && ($min === null || $value >= $min - 1e-9) && ($max === null || $value <= $max + 1e-9);

            if (($check['pass'] ?? null) !== $pass) {
                throw new RuntimeException("Phép đo {$required['key']} khai kết luận khác kết quả server tính theo ngưỡng cấu hình — không lưu.");
            }

            if (! $pass && $required['optional']) {
                $warned[] = $required['key'];
            } elseif (! $pass) {
                $failed[] = $required['key'];
            }
        }

        $missing = (array) ($report['missing'] ?? ['missing']);

        if ($missing !== []) {
            $failed[] = 'thiếu dữ liệu: '.implode(', ', array_map('strval', $missing));
        }

        return ['pass' => $failed === [], 'failed' => $failed, 'warned' => $warned];
    }

    private static function glbError(string $bytes): ?string
    {
        if (strlen($bytes) < 28 || substr($bytes, 0, 4) !== 'glTF') {
            return 'không phải glTF nhị phân';
        }

        [, $version, $total] = array_values(unpack('V3', substr($bytes, 0, 12)));

        if ($version !== 2 || $total !== strlen($bytes)) {
            return 'sai phiên bản hoặc độ dài khai báo';
        }

        ['l' => $jsonLength, 't' => $jsonType] = unpack('Vl/Vt', substr($bytes, 12, 8));

        if ($jsonType !== 0x4E4F534A || 20 + $jsonLength > $total) {
            return 'thiếu chunk JSON';
        }

        $gltf = json_decode(substr($bytes, 20, $jsonLength), true);

        if (! is_array($gltf) || ($gltf['asset']['version'] ?? null) !== '2.0' || empty($gltf['scenes']) || empty($gltf['meshes'])) {
            return 'JSON không có asset 2.0, scene hoặc mesh';
        }

        $binOffset = 20 + $jsonLength;
        $binLength = 0;

        if ($binOffset + 8 <= $total) {
            ['l' => $binLength, 't' => $binType] = unpack('Vl/Vt', substr($bytes, $binOffset, 8));

            if ($binType !== 0x004E4942 || $binOffset + 8 + $binLength > $total) {
                return 'chunk BIN hỏng';
            }
        }

        $buffer = (int) ($gltf['buffers'][0]['byteLength'] ?? 0);

        if ($buffer <= 0 || $buffer > $binLength) {
            return 'buffer nhị phân thiếu hoặc ngắn hơn khai báo';
        }

        foreach ((array) $gltf['meshes'] as $mesh) {
            foreach ((array) ($mesh['primitives'] ?? []) as $primitive) {
                $accessor = $gltf['accessors'][$primitive['attributes']['POSITION'] ?? -1] ?? null;
                $view = is_array($accessor) ? ($gltf['bufferViews'][$accessor['bufferView'] ?? -1] ?? null) : null;

                if (! is_array($view) || (int) ($view['byteOffset'] ?? 0) + (int) ($view['byteLength'] ?? 0) > $buffer || (int) ($accessor['count'] ?? 0) < 3) {
                    return 'mesh không có toạ độ đỉnh đọc được';
                }
            }
        }

        return null;
    }
}
