<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoPlanningStage;
use App\Video\Proxy\ProxySpecCompiler;
use App\Video\Proxy\ProxySpecValidator;
use App\Video\Proxy\ProxyVerdict;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Proxy 3D (Phase 3P): trang, revision, dieu phoi dung va duyet.
 *
 * Moi lan "Luu mapping & dung" = mot revision PROXY_3D (execution proxy-3d-v2): mapping + ProxySpec
 * bien dich tu nguon dong bang cua master da duyet; spec du dieu kien thi worker Node dung, xuat GLB,
 * render anh kiem va doi chieu tham chieu. Dau vao trung (nguon, spec, mapping, tham chieu, code bo
 * dung, nguong) thi tra revision da co, khong dung lai. Mo trang khong dung, khong goi model.
 */
final class YachtProxyService
{
    public const STATUS_LABELS = [
        'spec_incomplete' => 'ProxySpec chưa đủ — chưa dựng',
        'checks_failed' => 'Đã dựng — chưa đạt',
        'awaiting_reference' => 'Đã dựng — chờ xác nhận tham chiếu',
        'passed' => 'Đạt — chờ duyệt',
    ];

    public function __construct(
        private readonly YachtDesignService $design,
        private readonly YachtProxySource $source,
        private readonly PlanningStageStore $stageStore,
        private readonly YachtProxyWorker $worker,
        private readonly YachtProxyReferenceService $references,
        private readonly FilesystemFactory $storage,
    ) {}

    /** @return array<string, mixed> */
    public function page(string $projectId): array
    {
        $design = $this->design->panel($projectId);
        $base = ['design_name' => $design['name'], 'design_stage_id' => $design['stage_id'], 'source_error' => null, 'mapping' => null, 'proxy_spec' => ['spec' => null, 'errors' => [], 'error' => null], 'references' => [], 'revisions' => [], 'approved_id' => null, 'approved_problem' => null];

        try {
            $source = $this->source->resolve($projectId);
        } catch (RuntimeException $e) {
            return ['source_error' => $e->getMessage(), 'proxy_spec' => ['spec' => null, 'errors' => [], 'error' => $e->getMessage()]] + $base;
        }

        $latest = $this->revisions($projectId)->first();
        $mapping = is_array($latest?->output_json['mapping'] ?? null) ? $latest->output_json['mapping'] : ['parts' => [], 'unused' => (object) [], 'confirmed' => (object) []];
        $spec = (new ProxySpecCompiler)->compile($source, self::specConfig(), $mapping);
        $approval = $this->approvedState($projectId);
        $current = $this->currentInput($source) + ['references_sha256' => $this->references->fingerprint($projectId, $source)['sha256']];

        return [
            'mapping' => $mapping,
            'proxy_spec' => ['spec' => $spec, 'errors' => (new ProxySpecValidator)->errors($spec), 'error' => null],
            'references' => $this->references->current($projectId, $source),
            'revisions' => $this->revisions($projectId)->map(fn (VideoPlanningStage $stage): array => $this->revisionView($stage, $current, $approval['stage']))->all(),
            'approved_id' => $approval['stage'] === null ? null : (string) $approval['stage']->id,
            'approved_problem' => $approval['problem'],
        ] + $base;
    }

    /** @return array{assumptions: array<string, mixed>, frames: array<string, mixed>, registry: list<string>, tolerances: array<string, mixed>} */
    public static function specConfig(): array
    {
        return [
            'assumptions' => (array) config('video.superyacht.proxy.assumptions'),
            'frames' => (array) config('video.superyacht.model_sheet.frames'),
            'registry' => array_keys((array) config('video.superyacht.build_state_text.parts')),
            'tolerances' => (array) config('video.superyacht.proxy.tolerances'),
        ];
    }

    /**
     * Luu mapping (+ xac nhan gia dinh) thanh revision va dung neu ProxySpec du dieu kien.
     *
     * @param  array<string, string>  $confirm  id gia dinh => gia tri JSON nguoi dung da thay khi tick
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function build(string $projectId, string $creator, string $mappingJson, array $confirm): array
    {
        $temp = [];

        try {
            $source = $this->source->resolve($projectId);
            $previous = $this->revisions($projectId)->first()?->output_json['mapping']['confirmed'] ?? [];
            $mapping = self::mapping($mappingJson, (array) $previous);
            $first = (new ProxySpecCompiler)->compile($source, self::specConfig(), $mapping);
            $values = array_column($first['assumptions'], 'value', 'id');

            foreach ($confirm as $id => $json) {
                $seen = json_decode((string) $json, true);

                if (! array_key_exists($id, $values) || ! ProxySpecCompiler::same($seen, $values[$id])) {
                    throw new RuntimeException("Giả định {$id} đã đổi giá trị so với lúc xác nhận — tải lại trang rồi xác nhận lại.");
                }

                $mapping['confirmed'][$id] = $values[$id];
            }

            $spec = (new ProxySpecCompiler)->compile($source, self::specConfig(), $mapping);
            $errors = (new ProxySpecValidator)->errors($spec);
            $refs = $this->references->forBuild($projectId, $source);
            $temp = $refs['temp'];
            $input = $this->currentInput($source) + [
                'spec_sha256' => $spec['spec_sha256'],
                'mapping_sha256' => hash('sha256', (string) json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'references_sha256' => $refs['sha256'],
            ];
        } catch (RuntimeException $e) {
            self::cleanup($temp);

            return [null, $e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::PROXY_3D, $input);

        if ($token === null) {
            self::cleanup($temp);

            return $reason === 'already_succeeded' ? [$stage, 'already_exists'] : [null, 'Không ghi được revision proxy ('.$reason.').'];
        }

        $disk = (string) config('video.openai_image.disk');
        $dir = $projectId.'/proxy/'.$stage->id;
        $created = [];

        try {
            $output = ['created_by' => $creator, 'mapping' => $mapping, 'spec' => $spec, 'spec_errors' => $errors];

            if (! $spec['ready'] || $errors !== []) {
                $output += ['status' => 'spec_incomplete', 'verdict' => ProxyVerdict::evaluate($spec, $errors, null, self::gate()), 'artifacts' => []];
            } else {
                $config = (array) config('video.superyacht.proxy');
                $result = $this->worker->run([
                    'job' => 'build',
                    'spec' => $spec,
                    'tolerances' => $config['tolerances'],
                    'fill' => $config['fill'],
                    'out_dir' => $this->storage->disk($disk)->path($dir),
                    'references' => $refs['references'],
                    'iou_views' => $config['iou_views'],
                    'iou_min' => $config['iou_min'],
                    'landmark_ratio' => $config['landmark_ratio'],
                ]);
                [$artifacts, $report, $cameras] = $this->collect($projectId, (string) $stage->id, $disk, $dir, (array) $result['files'], $created);
                $verdict = ProxyVerdict::evaluate($spec, $errors, $report, self::gate(), $refs['cameras']);
                $output += [
                    'status' => $verdict['pass'] ? 'passed' : ($verdict['failed'] === [] ? 'awaiting_reference' : 'checks_failed'),
                    'report' => $report,
                    'cameras' => $cameras,
                    'verdict' => $verdict,
                    'artifacts' => $artifacts,
                ];
            }

            if (! $this->stageStore->finishSucceeded((string) $stage->id, $token, '', $output)) {
                throw new RuntimeException('lượt dựng bị giành mất');
            }
        } catch (Throwable $e) {
            $this->stageStore->finishFailed((string) $stage->id, $token, $e->getMessage());
            VideoArtifact::query()->whereIn('id', $created)->delete();
            $this->storage->disk($disk)->deleteDirectory($dir);

            return [null, 'Không dựng được proxy ('.$e->getMessage().').'];
        } finally {
            self::cleanup($temp);
        }

        return [VideoPlanningStage::query()->find($stage->id), 'built'];
    }

    /** @return array{0: bool, 1: string} */
    public function approve(string $projectId, string $stageId, ?string $adminId): array
    {
        $stage = $this->revisions($projectId)->first(fn (VideoPlanningStage $s): bool => (string) $s->id === $stageId);

        if ($stage === null) {
            return [false, 'Revision proxy không tồn tại trong dự án này.'];
        }

        try {
            $source = $this->source->resolve($projectId);
            $this->validate($projectId, $stage, $source);
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        [$review, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::PROXY_3D_REVIEW, [
            'execution' => (string) config('video.superyacht.proxy.review_execution'),
            'proxy_stage_id' => (string) $stage->id,
            'source_sha256' => (string) $source['source_sha256'],
        ], true);

        if ($token === null) {
            return [false, 'Không ghi được lượt duyệt proxy ('.$reason.').'];
        }

        $saved = $this->stageStore->finishSucceeded((string) $review->id, $token, '', ['approved_by' => $adminId, 'approved_at' => now()->toIso8601String()]);

        return $saved ? [true, 'approved'] : [false, 'Lượt duyệt proxy bị giành mất khi lưu — tải lại trang.'];
    }

    /**
     * Revision da duyet CON HIEU LUC: cung dieu kien nhu luc duyet (nguon, code bo dung, nguong,
     * tham chieu/camera, spec, artifact nguyen ven, cong dat) kiem lai moi lan doc — doi bat ky
     * dieu kien nao sau khi duyet thi khong tra revision cu cho cac phase sau.
     */
    public function approvedFor(string $projectId): ?VideoPlanningStage
    {
        return $this->approvedState($projectId)['stage'];
    }

    /** @return array{stage: ?VideoPlanningStage, problem: ?string} problem: revision da duyet nhung het hieu luc */
    public function approvedState(string $projectId): array
    {
        try {
            $source = $this->source->resolve($projectId);
        } catch (RuntimeException $e) {
            return ['stage' => null, 'problem' => null];
        }

        $review = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::PROXY_3D_REVIEW->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(fn (VideoPlanningStage $row): bool => ($row->input_json['execution'] ?? null) === config('video.superyacht.proxy.review_execution') && ($row->input_json['source_sha256'] ?? null) === $source['source_sha256']);
        $stage = $review === null ? null : $this->revisions($projectId)->first(fn (VideoPlanningStage $s): bool => (string) $s->id === ($review->input_json['proxy_stage_id'] ?? null));

        if ($stage === null) {
            return ['stage' => null, 'problem' => null];
        }

        try {
            $this->validate($projectId, $stage, $source);
        } catch (RuntimeException $e) {
            return ['stage' => null, 'problem' => 'Revision '.$stage->planning_revision.' đã duyệt nhưng hết hiệu lực: '.$e->getMessage()];
        }

        return ['stage' => $stage, 'problem' => null];
    }

    /**
     * Xac nhan camera cua mot goc theo ProxySpec hien hanh (mapping cua revision gan nhat).
     *
     * @return array{0: bool, 1: string}
     */
    public function confirmCamera(string $projectId, string $view, string $creator): array
    {
        try {
            $source = $this->source->resolve($projectId);
            $mapping = $this->revisions($projectId)->first()?->output_json['mapping'] ?? [];
            $spec = (new ProxySpecCompiler)->compile($source, self::specConfig(), (array) $mapping);
            $camera = collect($spec['cameras'])->firstWhere('view', $view) ?? throw new RuntimeException("ProxySpec không có camera {$view}.");
        } catch (RuntimeException $e) {
            return [false, $e->getMessage()];
        }

        [$artifact, $reason] = $this->references->confirmCamera($projectId, $view, $camera, $creator);

        return [$artifact !== null, $reason];
    }

    /**
     * Dieu kien hieu luc cua mot revision cho nguon hien hanh — dung chung khi duyet va khi doc lai.
     *
     * @param  array<string, mixed>  $source
     */
    private function validate(string $projectId, VideoPlanningStage $stage, array $source): void
    {
        foreach ($this->currentInput($source) as $key => $value) {
            if (($stage->input_json[$key] ?? null) !== $value) {
                throw new RuntimeException('Revision dựng từ nguồn, code bộ dựng hoặc ngưỡng cũ ('.$key.') — dựng lại từ trang hiện tại.');
            }
        }

        $refs = $this->references->fingerprint($projectId, $source);

        if ($refs['sha256'] !== ($stage->input_json['references_sha256'] ?? null)) {
            throw new RuntimeException('Tham chiếu camera/mask/mốc đã đổi sau khi dựng revision này — dựng lại.');
        }

        $spec = (array) ($stage->output_json['spec'] ?? []);
        $errors = (new ProxySpecValidator)->errors($spec);

        if (($spec['spec_sha256'] ?? null) !== ($stage->input_json['spec_sha256'] ?? null) || $errors !== []) {
            throw new RuntimeException('ProxySpec lưu trong revision không còn hợp lệ: '.implode('; ', array_slice($errors, 0, 3)));
        }

        $this->intact($stage);
        $verdict = ProxyVerdict::evaluate($spec, $errors, $stage->output_json['report'] ?? null, self::gate(), $refs['cameras']);

        if (! $verdict['pass']) {
            throw new RuntimeException('Revision chưa đạt cổng duyệt: '.implode('; ', array_slice([...$verdict['failed'], ...$verdict['pending']], 0, 4)));
        }
    }

    /** Nguong cong duyet (config). @return array{iou_views: list<string>, iou_min: float} */
    public static function gate(): array
    {
        return ['iou_views' => array_values((array) config('video.superyacht.proxy.iou_views')), 'iou_min' => (float) config('video.superyacht.proxy.iou_min')];
    }

    /**
     * Phan dau vao revision phu thuoc nguon/code/nguong hien hanh (khong gom mapping/spec/tham chieu).
     *
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private function currentInput(array $source): array
    {
        $config = (array) config('video.superyacht.proxy');

        return [
            'execution' => (string) $config['execution'],
            'source_sha256' => (string) $source['source_sha256'],
            'builder_sha256' => YachtProxyWorker::builderSha256(),
            'config_sha256' => hash('sha256', (string) json_encode([$config['tolerances'], $config['fill'], $config['iou_min'], $config['iou_views'], $config['landmark_ratio']], JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    /**
     * Mapping nguoi dung nhap: chi parts (danh sach) va unused (moc => ly do); xac nhan cu mang sang.
     *
     * @param  array<string, mixed>  $previous
     * @return array{parts: list<array<string, mixed>>, unused: array<string, string>, confirmed: array<string, mixed>}
     */
    private static function mapping(string $json, array $previous): array
    {
        if (strlen($json) > (int) config('video.superyacht.proxy.max_mapping_bytes')) {
            throw new RuntimeException('Mapping quá lớn.');
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Mapping không phải JSON hợp lệ: '.$e->getMessage().'.');
        }

        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['parts', 'unused']) !== []
            || ! array_is_list((array) ($data['parts'] ?? [])) || (isset($data['unused']) && (! is_array($data['unused']) || (array_is_list($data['unused']) && $data['unused'] !== [])))) {
            throw new RuntimeException('Mapping phải là object {"parts": [...], "unused": {"mốc": "lý do"}}.');
        }

        foreach ((array) ($data['unused'] ?? []) as $name => $reason) {
            if (! is_string($reason) || trim($reason) === '') {
                throw new RuntimeException("Mốc unused {$name} phải ghi lý do.");
            }
        }

        return ['parts' => array_values((array) ($data['parts'] ?? [])), 'unused' => (array) ($data['unused'] ?? []), 'confirmed' => $previous];
    }

    /**
     * Ghi artifact cho tung file worker tao (kiem sha256 tung file), doc report/cameras, va doc lai
     * GLB o PHP doi chieu so node voi geometry.
     *
     * @param  array<string, array{sha256: string, bytes: int}>  $files
     * @param  list<string>  $created
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function collect(string $projectId, string $stageId, string $disk, string $dir, array $files, array &$created): array
    {
        $artifacts = [];
        $report = null;
        $cameras = null;
        $glbNodes = null;

        foreach ($files as $name => $meta) {
            $path = $dir.'/'.$name;
            $bytes = $this->storage->disk($disk)->get($path);

            if (! is_string($bytes) || ! hash_equals((string) $meta['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException("File {$name} của worker thiếu hoặc không khớp sha256.");
            }

            [$kind, $view] = self::kind((string) $name);
            $info = str_ends_with($name, '.png') ? @getimagesizefromstring($bytes) : false;
            $artifact = VideoArtifact::create([
                'project_id' => $projectId,
                'artifact_type' => match (true) { str_ends_with($name, '.glb') => 'model', str_ends_with($name, '.png') => 'image', $name === 'manifest.json' => 'manifest', default => 'json' },
                'role' => 'proxy_'.$kind,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => match (true) { str_ends_with($name, '.glb') => 'model/gltf-binary', str_ends_with($name, '.png') => 'image/png', default => 'application/json' },
                'file_size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'width' => $info === false ? null : $info[0],
                'height' => $info === false ? null : $info[1],
                'metadata_json' => ['proxy_stage_id' => $stageId, 'file' => $name, 'kind' => $kind, 'view_id' => $view],
            ]);
            $created[] = (string) $artifact->id;
            $artifacts[] = ['file' => $name, 'kind' => $kind, 'view_id' => $view, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256];

            if ($name === 'report.json') {
                $report = json_decode($bytes, true);
            } elseif ($name === 'cameras.json') {
                $cameras = json_decode($bytes, true);
            } elseif ($name === 'proxy.glb') {
                $glbNodes = self::glbNodes($bytes);
            }
        }

        if (! is_array($report) || ! is_array($cameras) || $glbNodes === null) {
            throw new RuntimeException('Worker không tạo đủ report.json, cameras.json và proxy.glb.');
        }

        if ($glbNodes !== ($report['stats']['meshes'] ?? null)) {
            throw new RuntimeException('proxy.glb đọc ở server có '.$glbNodes.' node, khác '.($report['stats']['meshes'] ?? '?').' mesh của báo cáo.');
        }

        return [$artifacts, $report, $cameras];
    }

    /** @return array{0: string, 1: ?string} */
    private static function kind(string $name): array
    {
        return match (true) {
            $name === 'proxy.glb' => ['glb', null],
            str_starts_with($name, 'state-') => ['state_glb', substr($name, 6, -4)],
            preg_match('/^(clay|parts|mask|depth|overlay)-(MS\d+)\.png$/', $name, $m) === 1 => [$m[1], $m[2]],
            default => [str_replace(['.json', '-'], ['', '_'], $name), null],
        };
    }

    /** So node mesh cua GLB (doc header + chunk JSON doc lap voi worker). */
    private static function glbNodes(string $bytes): int
    {
        if (strlen($bytes) < 28 || substr($bytes, 0, 4) !== 'glTF' || unpack('V', substr($bytes, 8, 4))[1] !== strlen($bytes)) {
            throw new RuntimeException('proxy.glb không phải glTF nhị phân hợp lệ.');
        }

        $length = unpack('V', substr($bytes, 12, 4))[1];
        $json = json_decode(substr($bytes, 20, $length), true);

        if (! is_array($json) || ($json['asset']['version'] ?? null) !== '2.0') {
            throw new RuntimeException('proxy.glb thiếu chunk JSON glTF 2.0.');
        }

        return count(array_filter((array) $json['nodes'], static fn (array $n): bool => isset($n['mesh'])));
    }

    /** Artifact cua revision con nguyen (file ton tai, dung sha256). */
    private function intact(VideoPlanningStage $stage): void
    {
        foreach ((array) ($stage->output_json['artifacts'] ?? []) as $entry) {
            $artifact = VideoArtifact::query()->whereKey($entry['artifact_id'] ?? null)->where('project_id', $stage->project_id)->first();
            $bytes = $artifact === null ? null : $this->storage->disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path);

            if (! is_string($bytes) || ! hash_equals((string) $entry['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Artifact '.($entry['file'] ?? '?').' của revision mất hoặc đã bị sửa — không duyệt được.');
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, VideoPlanningStage> */
    private function revisions(string $projectId): \Illuminate\Support\Collection
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::PROXY_3D->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->filter(fn (VideoPlanningStage $s): bool => ($s->input_json['execution'] ?? null) === config('video.superyacht.proxy.execution'))
            ->values();
    }

    /**
     * @param  array<string, string>  $current
     * @return array<string, mixed>
     */
    private function revisionView(VideoPlanningStage $stage, array $current, ?VideoPlanningStage $approved): array
    {
        $out = (array) $stage->output_json;
        $stale = array_keys(array_filter($current, fn (string $v, string $k): bool => ($stage->input_json[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));

        return [
            'id' => (string) $stage->id,
            'revision' => (int) $stage->planning_revision,
            'created_at' => $stage->finished_at ?? $stage->created_at,
            'created_by' => (string) ($out['created_by'] ?? ''),
            'status' => (string) ($out['status'] ?? ''),
            'current' => $stale === [],
            'stale' => $stale,
            'approved' => $approved !== null && (string) $approved->id === (string) $stage->id,
            'verdict' => (array) ($out['verdict'] ?? []),
            'checks' => (array) ($out['report']['checks'] ?? []),
            'comparison' => (array) ($out['report']['comparison']['views'] ?? []),
            'projected' => (array) ($out['report']['comparison']['projected'] ?? []),
            'states' => (array) ($out['report']['states'] ?? []),
            'artifacts' => collect((array) ($out['artifacts'] ?? []))->keyBy('file')->all(),
            'cameras' => (array) ($out['cameras'] ?? []),
        ];
    }

    /** @param list<string> $paths */
    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
