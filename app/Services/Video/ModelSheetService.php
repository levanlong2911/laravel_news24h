<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Video\Llm\JsonAnswer;
use App\Video\Media\MediaModelRegistry;
use App\Video\YachtDesign\YachtDesignCheck;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ModelSheetService
{
    public const OPERATION = 'model_sheet';

    public const TASK = 'model_sheet';

    public const EXECUTION = 'model-sheet-design-v2';

    public const REVIEW_EXECUTION = 'model-sheet-review-v1';

    public const CODE_SUFFIX = '_model_sheet_v';

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly DesignImageStore $images,
        private readonly DesignImageDirectRenderer $renderer,
        private readonly MediaModelRegistry $registry,
        private readonly YachtDesignService $design,
        private readonly YachtProxyService $proxy,
    ) {}

    /** @return array{0: ?VideoPlanningStage, 1: ?string} */
    private function activeSet(string $projectId): array
    {
        try {
            [, $input, $snapshot] = $this->source($projectId);
        } catch (RuntimeException $e) {
            return [null, $e->getMessage()];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::MODEL_SHEET, $input);

        if ($token === null) {
            return $reason === 'already_succeeded' && $stage !== null ? [$stage, null] : [null, 'Không mở được bộ model sheet ('.$reason.').'];
        }

        return $this->stageStore->finishSucceeded((string) $stage->id, $token, '', $snapshot)
            ? [VideoPlanningStage::query()->find($stage->id), null]
            : [null, 'Lượt mở bộ model sheet bị giành mất khi lưu — tải lại trang.'];
    }

    /**
     * @param  array{view_id: string, model: string, size: string, quality: string, variations: int, attempt: string, prompt_sha256: string}  $choice
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function render(string $projectId, string $creator, array $choice): array
    {
        try {
            [$set, $error] = $this->activeSet($projectId);
            $set ?? throw new RuntimeException((string) $error);
            $snapshot = (array) $set->output_json;
            $view = $this->view($snapshot, $choice['view_id']);

            if (! hash_equals(hash('sha256', $view['prompt']), $choice['prompt_sha256'])) {
                throw new RuntimeException("Prompt {$view['id']} trên trang không còn khớp bộ model sheet — tải lại trang.");
            }

            $this->assertCompatible($snapshot);

            if (($snapshot['design_missing'] ?? []) !== []) {
                throw new RuntimeException('Thiết kế thiếu dữ liệu bắt buộc cho model sheet: '.implode(', ', (array) $snapshot['design_missing']).'.');
            }

            [$source, $sourceArtifact] = $view['id'] === $this->firstViewId($snapshot) ? [null, null] : $this->approvedFirstView($projectId, (string) $set->id);
            $frame = config('video.superyacht.model_sheet.frames.'.$view['id']);

            if (is_array($frame) && $choice['size'] !== $frame['width'].'x'.$frame['height']) {
                throw new RuntimeException("Khổ ảnh {$view['id']} phải là {$frame['width']}x{$frame['height']} theo khung proxy đã duyệt.");
            }

            $spec = $this->spec($set, $view, $this->settings($choice), $choice['attempt'], $source, $sourceArtifact);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return [null, $e->getMessage()];
        }

        [$image, $reason] = $this->images->createModelSheetView($projectId, $creator, $spec);

        if ($image === null) {
            return [null, 'Không tạo được ô ảnh ('.$reason.').'];
        }

        if ($reason === 'already_exists') {
            return [$image, 'already_exists'];
        }

        return $this->renderer->renderNow((string) $image->id);
    }

    /** @return array{0: bool, 1: string} */
    public function approve(string $projectId, string $artifactId, ?string $adminId): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [false, (string) $error];
        }

        $artifact = VideoArtifact::query()->whereKey($artifactId)->where('project_id', $projectId)->first();
        $image = $artifact?->design_image_id === null ? null : VideoDesignImage::query()->whereKey($artifact->design_image_id)->first();
        $spec = is_array($image?->prompt_spec_json) ? $image->prompt_spec_json : [];

        if ($image === null || $image->image_type !== DesignImageStore::MODEL_SHEET_TYPE || ($spec['model_sheet_set_id'] ?? null) !== (string) $set->id) {
            return [false, 'Ảnh này không thuộc bộ model sheet hiện tại.'];
        }

        if (($spec['view_id'] ?? null) !== $this->firstViewId((array) $set->output_json)) {
            $first = $this->approvedFirstView($projectId, (string) $set->id, false);

            if ($first === null || ($spec['source_artifact_id'] ?? null) !== (string) $first[1]->id) {
                return [false, 'Ảnh này được render từ một MS1 khác MS1 đang duyệt — cần xác nhận lại bằng một lượt render mới.'];
            }
        }

        [$done, $reason] = $this->images->approve($projectId, $artifactId, $adminId, null, DesignImageStore::MODEL_SHEET_TYPE);

        return $done ? [true, 'approved'] : [false, 'Không duyệt được ảnh ('.$reason.').'];
    }

    /** @return array{0: ?VideoPlanningStage, 1: string} */
    public function review(string $projectId, ?string $adminId): array
    {
        [$set, $error] = $this->activeSet($projectId);

        if ($set === null) {
            return [null, (string) $error];
        }

        $state = $this->views($projectId, $set);

        if ($state['review_input'] === null) {
            return [null, 'Bộ model sheet chưa đủ tám góc được duyệt và còn hợp lệ.'];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage($projectId, PlanningStageName::MODEL_SHEET_REVIEW, $state['review_input']);

        if ($token === null) {
            return $reason === 'already_succeeded' ? [$stage, 'already_reviewed'] : [null, 'Không ghi được lượt duyệt bộ ('.$reason.').'];
        }

        $saved = $this->stageStore->finishSucceeded((string) $stage->id, $token, '', ['approved_by' => $adminId, 'approved_at' => now()->toIso8601String()]);

        return $saved ? [VideoPlanningStage::query()->find($stage->id), 'reviewed'] : [null, 'Lượt duyệt bộ bị giành mất khi lưu — tải lại trang.'];
    }

    /** @return array<string, mixed> */
    public function panel(string $projectId, string $creator = ''): array
    {
        $design = $this->design->panel($projectId);
        [$active, $sourceError] = $this->activeSet($projectId);
        $set = $active ?? $this->currentSet($projectId);

        $base = [
            'design_name' => $design['name'],
            'design_stage_id' => $design['stage_id'],
            'design_warnings' => count($design['check']['warnings'] ?? []),
            'proxy_ready' => $design['current'] && ($design['check']['verdict'] ?? null) === YachtDesignCheck::PASS,
            'source_error' => $sourceError,
            'models' => array_map(static fn (array $entry): array => [
                'value' => (string) $entry['model'],
                'label' => (string) $entry['label'],
                'default' => $entry['default'] === true,
            ], $this->registry->forTask(self::TASK)),
            'default_size' => (string) config('video.superyacht.model_sheet.default_size'),
            'next_image_code' => trim($creator) === '' ? '' : $this->images->nextImageCode($projectId, $creator, self::CODE_SUFFIX),
            'set' => null,
            'rows' => [],
            'complete' => false,
            'reviewed' => false,
        ];

        if ($set === null) {
            return $base;
        }

        $state = $this->views($projectId, $set);
        $review = $state['review_input'] === null ? null : $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_REVIEW, $state['review_input']);

        return [
            'set' => [
                'id' => (string) $set->id,
                'active' => $active !== null,
                'design_stage_id' => (string) ($set->input_json['design_stage_id'] ?? ''),
                'phase_revision' => (int) ($set->input_json['phase_revision'] ?? 0),
                'current_design' => ($set->input_json['design_stage_id'] ?? null) === $design['stage_id'],
                'compatible_error' => $this->compatibilityError((array) $set->output_json),
                'design_missing' => array_values((array) ($set->output_json['design_missing'] ?? [])),
            ],
            'rows' => $state['rows'],
            'complete' => $state['review_input'] !== null,
            'reviewed' => $review !== null,
        ] + $base;
    }

    /** @return array{rows: list<array<string, mixed>>, review_input: ?array<string, mixed>} */
    private function views(string $projectId, VideoPlanningStage $set): array
    {
        $snapshot = (array) $set->output_json;
        $cells = $this->images->modelSheetCellsFor($projectId, (string) $set->id);
        $first = $this->approvedFirstView($projectId, (string) $set->id, false);
        $firstArtifactId = $first === null ? null : (string) $first[1]->id;
        $rows = [];
        $complete = true;

        foreach ($snapshot['views'] as $index => $view) {
            $isFirst = $index === 0;
            $viewCells = array_values(array_map(
                static fn (array $cell): array => $cell + ['stale' => ! $isFirst && $cell['source_artifact_id'] !== $firstArtifactId],
                array_filter($cells, static fn (array $cell): bool => $cell['view_id'] === $view['id']),
            ));
            $approvedCell = array_values(array_filter($viewCells, static fn (array $cell): bool => $cell['status'] === DesignImageStatus::APPROVED->value))[0] ?? null;
            $approvedArtifact = $approvedCell === null ? null : VideoArtifact::query()->find($approvedCell['selected_artifact_id']);
            $approved = $approvedCell === null || $approvedArtifact === null ? null : [
                'id' => $approvedCell['id'],
                'artifact_id' => (string) $approvedArtifact->id,
                'artifact_sha256' => (string) $approvedArtifact->sha256,
                'stale' => $approvedCell['stale'],
            ];
            $complete = $complete && $approved !== null && ! $approved['stale'];
            $rows[] = [
                'id' => $view['id'],
                'view' => $view['view'],
                'prompt' => $view['prompt'],
                'prompt_sha256' => hash('sha256', $view['prompt']),
                'size' => ($frame = config('video.superyacht.model_sheet.frames.'.$view['id'])) ? $frame['width'].'x'.$frame['height'] : null,
                'attempt' => (string) Str::uuid(),
                'cells' => $viewCells,
                'approved' => $approved,
                'locked' => ! $isFirst && $firstArtifactId === null,
            ];
        }

        return [
            'rows' => $rows,
            'review_input' => $complete ? [
                'execution' => self::REVIEW_EXECUTION,
                'model_sheet_set_id' => (string) $set->id,
                'approvals' => array_map(static fn (array $row): array => [
                    'view_id' => $row['id'],
                    'image_id' => $row['approved']['id'],
                    'artifact_id' => $row['approved']['artifact_id'],
                    'artifact_sha256' => $row['approved']['artifact_sha256'],
                ], $rows),
            ] : null,
        ];
    }

    /** @return array{0: VideoPlanningStage, 1: array<string, mixed>, 2: array<string, mixed>} */
    private function source(string $projectId): array
    {
        $panel = $this->design->panel($projectId);

        if (! $panel['current'] || ($panel['check']['verdict'] ?? null) !== YachtDesignCheck::PASS || ! is_string($panel['stage_id'])) {
            throw new RuntimeException('Chưa có bản thiết kế Phase 3 hiện hành đạt kiểm tự động.');
        }

        $stage = VideoPlanningStage::query()->whereKey($panel['stage_id'])->where('project_id', $projectId)->first();

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            throw new RuntimeException('Bản thiết kế nguồn không thuộc dự án hoặc chưa chạy thành công.');
        }

        $proxyStage = $this->proxy->approvedFor($projectId) ?? throw new RuntimeException('Chưa duyệt proxy 3D cho bản thiết kế này — mở trang Proxy 3D để dựng, lưu và duyệt.');

        $raw = (string) $stage->raw_response;
        $design = JsonAnswer::object($raw);
        $canonicalHash = $stage->output_json['design']['data']['canonical']['hash'] ?? null;

        if ($design === null || ! is_string($canonicalHash) || $canonicalHash === '') {
            throw new RuntimeException('Bản thiết kế nguồn không đọc được JSON hoặc thiếu hash canonical.');
        }

        $config = (array) config('video.superyacht.model_sheet');
        $phase = AiPhase::query()->find($stage->input_json['phase_id'] ?? null) ?? throw new RuntimeException('Không tìm thấy prompt phase-3 mà bản thiết kế đã dùng.');
        [$block, $missing, $details] = self::designBlock($design, $canonicalHash);
        $snapshot = [
            'execution' => self::EXECUTION,
            'phase_id' => (string) $phase->id,
            'phase_revision' => (int) $phase->revision,
            'prompt_sha256' => (string) $phase->content_sha256,
            'suffix' => (string) $config['suffix'],
            'design_block_sha256' => hash('sha256', $block),
            'design_missing' => $missing,
            'views' => array_map(static fn (array $view, int $index): array => [
                'id' => (string) $view['id'],
                'view' => (string) $view['view'],
                'prompt' => implode("\n\n", [
                    $block,
                    implode("\n", array_filter([
                        (string) $view['view'],
                        (string) ($details[$view['id']] ?? ''),
                        (string) ($config['cameras'][$view['id']] ?? ''),
                        (string) ($config['framing'][$view['id']] ?? ''),
                        (string) $config['occlusion'],
                        $index === 0 ? '' : (string) $config['reference_caption'],
                    ], static fn (string $line): bool => $line !== '')),
                    (string) $config['suffix'],
                ]),
            ], array_values((array) $config['views']), array_keys(array_values((array) $config['views']))),
        ];

        if (($error = $this->compatibilityError($snapshot)) !== null) {
            throw new RuntimeException($error);
        }

        $input = [
            'execution' => self::EXECUTION,
            'design_stage_id' => (string) $stage->id,
            'design_raw_sha256' => hash('sha256', $raw),
            'design_canonical_hash' => $canonicalHash,
            'phase_id' => (string) $phase->id,
            'phase_revision' => (int) $phase->revision,
            'prompt_sha256' => (string) $phase->content_sha256,
            'config_sha256' => hash('sha256', (string) json_encode(array_diff_key($snapshot, array_flip(['execution'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'proxy_stage_id' => (string) $proxyStage->id,
            'proxy_input_sha256' => (string) ($proxyStage->input_json['input_sha256'] ?? ''),
            'proxy_builder_sha256' => (string) ($proxyStage->input_json['builder_sha256'] ?? ''),
        ];

        return [$stage, $input, $snapshot];
    }

    /** @param  array<string, mixed>  $snapshot */
    private function compatibilityError(array $snapshot): ?string
    {
        $phase = AiPhase::query()->find($snapshot['phase_id'] ?? null);

        if ($phase === null || (string) $phase->content_sha256 !== (string) ($snapshot['prompt_sha256'] ?? '')) {
            return 'Prompt phase-3 mà bộ model sheet chốt không còn khớp hash.';
        }

        $content = (string) $phase->prompt_content;
        $ids = array_column($snapshot['views'], 'id');
        preg_match_all('/^\|\s*(MS\d+)\s*\|/m', $content, $rows);

        if (count(array_unique($ids)) !== count($ids) || $rows[1] !== $ids) {
            return 'Danh sách góc trong config ('.implode(', ', $ids).') không khớp bảng model sheet của prompt phase-3 ('.implode(', ', $rows[1]).').';
        }

        foreach ($snapshot['views'] as $view) {
            if (! str_contains($content, '| '.$view['id'].' | '.$view['view'].' |')) {
                return "Mô tả góc {$view['id']} trong config không khớp nguyên văn prompt phase-3.";
            }
        }

        foreach ($snapshot['views'] as $view) {
            if (trim((string) config('video.superyacht.model_sheet.cameras.'.$view['id'], '')) === '') {
                return "Config model sheet thiếu thông số camera của {$view['id']}.";
            }
        }

        if (preg_match('/^### Step 10\b[^\n]*\n+Each prompt = [^“\n]*“([^”\n]+)”/m', $content, $step) !== 1) {
            return 'Không tìm thấy câu nền studio trong Step 10 của prompt phase-3.';
        }

        foreach (explode(', ', (string) $snapshot['suffix']) as $phrase) {
            if (! in_array($phrase, explode(', ', $step[1]), true)) {
                return "Cụm \"{$phrase}\" của câu nền studio trong config không có nguyên văn trong câu nền Step 10.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array{0: string, 1: list<string>, 2: array<string, string>}
     */
    private static function designBlock(array $design, string $canonicalHash): array
    {
        $config = (array) config('video.superyacht.model_sheet.design');
        $approvedPath = 'video.superyacht.model_sheet.approved['.$canonicalHash.']';
        $approved = config('video.superyacht.model_sheet.approved')[$canonicalHash] ?? null;
        $missing = [];
        $texts = static function (array $paths) use ($design, &$missing): array {
            $found = [];

            foreach ($paths as $path) {
                $value = self::at($design, (string) $path);

                if (is_string($value) && trim($value) !== '') {
                    $found[] = trim($value);
                } else {
                    $missing[] = (string) $path;
                }
            }

            return $found;
        };

        $dimensions = self::at($design, (string) $config['dimensions']);
        $measures = [];

        foreach ((array) $config['dimension_labels'] as $key => $label) {
            $entry = is_array($dimensions) ? ($dimensions[$key] ?? null) : null;

            if (is_array($entry) && is_numeric($entry['value'] ?? null) && is_string($entry['unit'] ?? null)) {
                $measures[] = $label.' '.$entry['value'].' '.$entry['unit'];
            } else {
                $missing[] = $config['dimensions'].'.'.$key;
            }
        }

        $decks = self::at($design, (string) $config['decks']);

        if (is_array($decks) && is_numeric($decks['value'] ?? null) && is_string($decks['unit'] ?? null)) {
            $measures[] = $decks['value'].' '.$decks['unit'];
        } else {
            $missing[] = (string) $config['decks'];
        }

        $innovations = self::at($design, (string) $config['innovations']);
        $innovations = is_array($innovations) ? array_values(array_filter($innovations, static fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null))) : [];
        $required = (array) $config['innovation_ids'];
        $features = [];

        foreach (array_diff(array_unique(array_column($innovations, 'id')), $required) as $extra) {
            $missing[] = $config['innovations'].'['.$extra.'] (ngoài '.implode(', ', $required).')';
        }

        foreach ($required as $id) {
            $matches = array_values(array_filter($innovations, static fn (array $item): bool => $item['id'] === $id));

            if (count($matches) !== 1) {
                $missing[] = $config['innovations'].'['.$id.']'.($matches === [] ? '' : ' (trùng '.count($matches).' lần)');

                continue;
            }

            $parts = [];

            foreach ((array) $config['innovation_fields'] as $field) {
                if (is_string($matches[0][$field] ?? null) && trim($matches[0][$field]) !== '') {
                    $parts[] = $field === 'name' ? rtrim(trim($matches[0][$field]), '.').'.' : trim($matches[0][$field]);
                } else {
                    $missing[] = $config['innovations'].'['.$id.'].'.$field;
                }
            }

            $features[] = $id.' '.implode(' ', $parts);
        }

        $colors = self::at($design, (string) $config['colors']);
        $colors = is_array($colors) ? array_values($colors) : [];
        $swatches = [];
        $roles = [];

        foreach ($colors as $index => $color) {
            $color = is_array($color) ? $color : [];

            foreach (['name', 'hex', 'role'] as $field) {
                if (! is_string($color[$field] ?? null) || trim($color[$field]) === '' || ($field === 'hex' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color[$field]) !== 1)) {
                    $missing[] = $config['colors'].'['.$index.'].'.$field;
                }
            }

            if (is_string($color['name'] ?? null) && is_string($color['hex'] ?? null) && is_string($color['role'] ?? null)) {
                $swatches[] = trim($color['name']).' '.trim($color['hex']).' '.trim($color['role']);
                $roles[] = trim($color['role']);
            }
        }

        foreach ((array) $config['color_roles'] as $role) {
            if (! in_array($role, $roles, true)) {
                $missing[] = $config['colors'].'[role='.$role.']';
            }
        }

        $avoid = self::at($design, (string) $config['avoid']);
        $avoid = array_values(array_filter(
            is_array($avoid) ? array_map(static fn (mixed $line): string => is_string($line) ? trim($line) : '', $avoid) : [],
            static fn (string $line): bool => $line !== '',
        ));
        $limits = self::at($design, (string) $config['operational_limits']);
        $limits = is_array($limits) ? array_values(array_filter(array_map(static fn (mixed $limit): ?string => is_array($limit) && is_string($limit['feature'] ?? null) ? $limit['feature'] : null, $limits))) : [];
        $state = '';

        if (! is_array($approved)) {
            $missing[] = $approvedPath;
        } else {
            $interior = array_values((array) ($approved['avoid_interior'] ?? []));

            foreach (array_diff($interior, $avoid) as $line) {
                $missing[] = $approvedPath.'.avoid_interior: "'.$line.'" không có trong '.$config['avoid'];
            }

            $avoid = array_values(array_diff($avoid, $interior));
            $chosen = array_values((array) ($approved['state_features'] ?? []));
            $state = trim((string) ($approved['state'] ?? ''));

            foreach (array_diff($chosen, $limits) as $feature) {
                $missing[] = $approvedPath.'.state_features: "'.$feature.'" không có trong '.$config['operational_limits'];
            }

            if (($chosen === []) !== ($state === '')) {
                $missing[] = $approvedPath.'.state';
            }
        }

        $details = [];

        foreach ((array) $config['view_details'] as $id => $path) {
            $views = self::at($design, (string) $path);
            $matches = array_values(array_filter(is_array($views) ? $views : [], static fn (mixed $view): bool => is_array($view) && ($view['id'] ?? null) === $id));

            if (count($matches) === 1 && is_string($matches[0]['view'] ?? null) && trim($matches[0]['view']) !== '') {
                $details[$id] = trim($matches[0]['view']);
            } else {
                $missing[] = $path.'['.$id.']'.(count($matches) > 1 ? ' (trùng '.count($matches).' lần)' : '');
            }
        }

        $reference = [];
        $axis = self::at($design, (string) $config['coordinate_x']);

        if (is_string($axis) && trim($axis) !== '') {
            $reference[] = 'x: '.trim($axis);
        } else {
            $missing[] = (string) $config['coordinate_x'];
        }

        $landmarks = self::at($design, (string) $config['landmarks']);
        $landmarks = is_array($landmarks) ? $landmarks : [];
        $crest = $landmarks[$config['crest']] ?? null;

        if (is_array($crest) && is_numeric($crest['x'] ?? null) && is_numeric($crest['z'] ?? null)) {
            $reference[] = 'Crest at x='.$crest['x'].', '.$crest['z'].' m above the design waterline.';
        } else {
            $missing[] = $config['landmarks'].'.'.$config['crest'];
        }

        $raised = [];

        foreach ($landmarks as $key => $mark) {
            if (! is_array($mark) || ($mark['above_profile'] ?? null) !== true) {
                continue;
            }

            if (! is_string($mark['above_profile_reason'] ?? null) || trim($mark['above_profile_reason']) === '' || ! is_numeric($mark['z'] ?? null)) {
                $missing[] = $config['landmarks'].'.'.$key;

                continue;
            }

            $raised[] = trim($mark['above_profile_reason']).' Its landmark elevation is '.$mark['z'].' m above the design waterline.';
        }

        array_push($reference, ...array_values(array_unique($raised)));

        $declared = self::at($design, (string) $config['deck_levels']);
        $declared = is_array($declared) ? array_values($declared) : [];

        foreach ($declared as $index => $deck) {
            foreach (['name' => is_array($deck) && is_string($deck['name'] ?? null) && trim($deck['name']) !== '', 'floor_z_m' => is_array($deck) && is_numeric($deck['floor_z_m'] ?? null)] as $field => $valid) {
                if (! $valid) {
                    $missing[] = $config['deck_levels'].'['.$index.'].'.$field;
                }
            }
        }

        $levels = array_values(array_filter($declared, static fn (mixed $deck): bool => is_array($deck) && is_string($deck['name'] ?? null) && trim($deck['name']) !== '' && is_numeric($deck['floor_z_m'] ?? null)));
        usort($levels, static fn (array $a, array $b): int => $a['floor_z_m'] <=> $b['floor_z_m']);
        $below = array_column(array_filter($levels, static fn (array $deck): bool => $deck['floor_z_m'] < 0), 'name');

        foreach (array_unique(array_diff_assoc(array_column($levels, 'name'), array_unique(array_column($levels, 'name')))) as $name) {
            $missing[] = $config['deck_levels'].'['.$name.'] (trùng tên)';
        }

        if (is_array($decks) && is_numeric($decks['value'] ?? null) && (int) $decks['value'] !== count($declared)) {
            $missing[] = $config['deck_levels'].' ('.count($declared).' boong, khác '.$config['decks'].' = '.$decks['value'].')';
        }

        if ($declared === []) {
            $missing[] = (string) $config['deck_levels'];
        } else {
            $reference[] = 'Decks from lowest: '.implode(', ', array_column($levels, 'name')).'.'
                .($below === [] ? '' : ' '.implode(' and ', array_filter([implode(', ', array_slice($below, 0, -1)), end($below)])).(count($below) === 1 ? ' has its floor' : ' have floors').' below the design waterline.');
        }

        $blocks = [
            implode(' ', array_filter([$measures === [] ? '' : implode(', ', $measures).'.', ...$reference, ...$texts((array) $config['geometry'])])),
            implode("\n", $features),
            implode(' ', array_filter([...$texts((array) $config['materials']), $swatches === [] ? '' : implode(', ', $swatches).'.'])),
            implode(' ', array_filter([...$texts((array) $config['requirements']), ...$avoid, $state])),
            is_array($approved) ? trim((string) ($approved['emphasis'] ?? '')) : '',
        ];

        return [implode("\n\n", array_values(array_filter($blocks, static fn (string $block): bool => trim($block) !== ''))), array_values(array_unique($missing)), $details];
    }

    /** @param  array<string, mixed>  $design */
    private static function at(array $design, string $path): mixed
    {
        $node = $design;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /** @param  array<string, mixed>  $snapshot */
    private function assertCompatible(array $snapshot): void
    {
        if (($error = $this->compatibilityError($snapshot)) !== null) {
            throw new RuntimeException($error.' Không gửi request.');
        }
    }

    /**
     * @param  array{model: string, size: string, quality: string, variations: int}  $choice
     * @return array{entry: array<string, mixed>, size: ImageSize, quality: ImageQuality, variations: ImageVariations}
     */
    private function settings(array $choice): array
    {
        $entry = $this->registry->find(self::TASK, 'openai:'.$choice['model']) ?? throw new RuntimeException("Model {$choice['model']} không dùng được cho model sheet.");
        $model = ImageModel::tryFrom($choice['model']) ?? throw new RuntimeException("Model {$choice['model']} không có trong ImageModel.");
        $size = ImageSize::tryFrom($choice['size']);
        $quality = ImageQuality::tryFrom($choice['quality']);
        $variations = ImageVariations::tryFrom($choice['variations']);

        if ($size === null || ! in_array($size->value, $entry['controls']['sizes'], true)) {
            throw new RuntimeException("Size {$choice['size']} không dùng được với {$entry['label']}.");
        }

        if ($quality === null || ! $model->supports($quality)) {
            throw new RuntimeException("Quality {$choice['quality']} không dùng được với {$entry['label']}.");
        }

        if ($variations === null || $variations->value > (int) $entry['max_variations']) {
            throw new RuntimeException("{$choice['variations']} ảnh vượt mức tối đa của {$entry['label']}.");
        }

        return ['entry' => $entry, 'size' => $size, 'quality' => $quality, 'variations' => $variations];
    }

    /**
     * @param  array{id: string, view: string, prompt: string}  $view
     * @param  array{entry: array<string, mixed>, size: ImageSize, quality: ImageQuality, variations: ImageVariations}  $settings
     * @return array<string, mixed>
     */
    private function spec(VideoPlanningStage $set, array $view, array $settings, string $attempt, ?VideoDesignImage $source, ?VideoArtifact $sourceArtifact): array
    {
        return [
            'project_id' => (string) $set->project_id,
            'operation' => self::OPERATION,
            'task' => self::TASK,
            'execution' => self::EXECUTION,
            'model_sheet_set_id' => (string) $set->id,
            'design_stage_id' => (string) $set->input_json['design_stage_id'],
            'design_canonical_hash' => (string) $set->input_json['design_canonical_hash'],
            'view_id' => $view['id'],
            'slot_index' => (int) substr($view['id'], 2),
            'attempt' => $attempt,
            'prompt' => $view['prompt'],
            'prompt_sha256' => hash('sha256', $view['prompt']),
            'provider' => $settings['entry']['provider'],
            'model' => $settings['entry']['model'],
            'pricing' => $settings['entry']['pricing'],
            'size' => $settings['size']->value,
            'quality' => $settings['quality']->value,
            'variations' => $settings['variations']->value,
            'aspect_ratio' => null,
            'image_size' => null,
            'source_image_id' => $source === null ? null : (string) $source->id,
            'source_artifact_id' => $sourceArtifact === null ? null : (string) $sourceArtifact->id,
            'source_artifact_sha256' => $sourceArtifact === null ? null : (string) $sourceArtifact->sha256,
        ];
    }

    private function currentSet(string $projectId): ?VideoPlanningStage
    {
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::MODEL_SHEET->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.execution')) = ?", [self::EXECUTION])
            ->orderByDesc('planning_revision')
            ->first();

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{id: string, view: string, prompt: string}
     */
    private function view(array $snapshot, string $viewId): array
    {
        foreach ($snapshot['views'] as $view) {
            if ($view['id'] === $viewId) {
                return $view;
            }
        }

        throw new RuntimeException("Góc {$viewId} không có trong bộ model sheet này.");
    }

    /** @param  array<string, mixed>  $snapshot */
    private function firstViewId(array $snapshot): string
    {
        return (string) ($snapshot['views'][0]['id'] ?? '');
    }

    /** @return ?array{0: VideoDesignImage, 1: VideoArtifact} */
    private function approvedFirstView(string $projectId, string $setId, bool $required = true): ?array
    {
        $image = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_TYPE)
            ->where('slot_index', 1)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.model_sheet_set_id')) = ?", [$setId])
            ->first();
        $artifact = $image === null ? null : VideoArtifact::query()->whereKey($image->selected_artifact_id)->where('project_id', $projectId)->first();

        if ($image === null || $artifact === null) {
            if ($required) {
                throw new RuntimeException('Cần duyệt một ảnh MS1 trước khi render các góc còn lại.');
            }

            return null;
        }

        return [$image, $artifact];
    }
}
