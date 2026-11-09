<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Moto\MotoCharacterPlanner;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MotoKeyframeSourceService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, what a person chose for a keyframe */
    public const CHOICES = 'moto_keyframe_choices';

    /** @var string what a keyframe whose inputs are no longer the ones in use says */
    public const STALE = 'Nguồn đã đổi — cần kiểm lại';

    /** @var list<string> the four cells of a shot, in the order their images are sent */
    private const CELLS = ['identity', 'design', 'environment', 'continuity'];

    /** @var array<string, array{0: string, 1: string}> the name of each kind and the state of a source that is still in use */
    private const KINDS = [
        'identity' => ['Model sheet', 'Đã duyệt'],
        'design' => ['Reference master', 'Đã duyệt'],
        'environment' => ['Ảnh nền', 'Đã chọn ở Phase 5'],
        'continuity' => ['Keyframe trước', 'Đã duyệt'],
        'character' => ['Nhân vật', 'Đã duyệt'],
    ];

    /** @var array<string, bool> whether each file read in this round of checks still matched its hash, by artifact id */
    private array $files = [];

    /** @var array<string, ?string> what reading the file of each keyframe found in this round of checks, by artifact id */
    private array $outputs = [];

    public function __construct(
        private readonly ShotSourceService $shots,
        private readonly ShotRenderPlanService $plans,
        private readonly ModelSheetService $sheets,
        private readonly PartsDetailService $references,
        private readonly LocationSourceService $locations,
        private readonly MotoCharacterReferenceService $characters,
    ) {}

    /**
     * @return array<string, mixed> everything a check of a keyframe reads: the approved shot plan with its stored shots, frame and image limit, the images in use now, the render row and keyframes of each shot and what a person chose
     *
     * @throws RuntimeException when no shot plan is approved or it no longer matches its approval
     */
    public function context(string $projectId): array
    {
        $this->files = [];
        $this->outputs = [];

        return ModelSheetService::readOnce(function () use ($projectId): array {
            $approved = $this->shots->approvedSource($projectId);
            $stage = VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($approved['stage_id']);
            $metadata = PlanningStageStore::metadataOf(is_array($stage->input_json) ? $stage->input_json : null);
            $rows = $this->plans->forStage($projectId, (string) $stage->id);
            $shotOf = [];

            foreach ($rows as $shotId => $row) {
                $shotOf[(string) $row->id] = (string) $shotId;
            }

            $images = [];
            $current = [];
            $found = $rows === [] ? collect() : VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->whereIn('render_scene_id', array_keys($shotOf))->with('artifacts')->orderByDesc('created_at')->orderByDesc('image_code')->get();

            foreach ($found as $image) {
                $shotId = $shotOf[(string) $image->render_scene_id];
                $images[$shotId][] = $image;

                if ($image->status === DesignImageStatus::APPROVED->value && $image->selected_artifact_id !== null) {
                    $current[$shotId] = $image;
                }
            }

            $sheet = $this->sheet($projectId);
            $reference = $this->masters($projectId);
            $plates = $this->plates($projectId);
            $ids = array_filter([$sheet['source']['artifact_id'] ?? null, ...array_keys($reference['masters']), ...array_column($plates, 'artifact_id')]);
            $stored = array_values((array) ($stage->output_json['derived']['shots'] ?? []));
            $people = MotoCharacterPlanner::plan($stored, (array) ($metadata['context']['characters'] ?? []), (array) config('video.moto.reference.character'));

            return [
                'people' => $people,
                'people_cells' => array_column($people['cells'], null, 'id'),
                'people_images' => $this->characters->approvedCells($projectId, $people),
                'project_id' => $projectId,
                'approved' => $approved,
                'stage' => $stage,
                'shots' => array_column((array) ($stage->output_json['derived']['shots'] ?? []), null, 'id'),
                'aspect_ratio' => (string) ($metadata['context']['aspect_ratio'] ?? ''),
                'limit' => (int) config('video.moto.shots.keyframe_reference_limit'),
                'sheet' => $sheet,
                'masters' => $reference['masters'],
                'part_masters' => $reference['part_masters'],
                'reference_problem' => $reference['problem'],
                'plates' => $plates,
                'rows' => $rows,
                'images' => $images,
                'current' => $current,
                'choices' => (array) (VideoProject::query()->whereKey($projectId)->value('metadata_json')[self::CHOICES][(string) $stage->id] ?? []),
                'artifacts' => VideoArtifact::query()->where('project_id', $projectId)->whereIn('id', $ids)->get()->keyBy('id')->all(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $context  what context() returned
     * @param  array<string, mixed>  $shot  a stored shot
     * @param  bool  $deep  read and hash every file, not only see that it is there
     * @return array{cards: list<array<string, mixed>>, slots: list<array<string, mixed>>, sources: list<array<string, mixed>>, problems: list<string>} the whole images of the shot, each with the other images a person may put in its place: the approved model sheet for every shot, the others as its reference needs ask; the same cards in the four cells of the screen, a cell with no image empty and sending nothing; the manifest they send in order, and why the shot cannot render
     */
    public function cards(array $context, array $shot, bool $deep = false): array
    {
        $needs = collect((array) $shot['reference_needs'])->groupBy('kind');
        $choice = (array) ($context['choices'][$shot['id']]['sources'] ?? []);
        $cards = [
            ...($needs->has('identity') || $context['sheet']['source'] !== null ? [$this->identityCard($context, $needs->get('identity', collect())->all())] : []),
            ...($needs->has('design') ? $this->designCards($context, $needs->get('design')->all(), $choice) : []),
            ...($needs->has('environment') ? [$this->environmentCard($context, $needs->get('environment')->all(), $choice)] : []),
            ...array_map(fn (array $need): array => $this->continuityCard($context, $need, $deep), $needs->get('continuity', collect())->all()),
            ...$this->characterCards($context, (string) $shot['id']),
        ];
        $sources = [];
        $problems = [];

        foreach ($cards as $index => $card) {
            $cards[$index]['problem'] ??= $card['source'] === null ? null : $this->fileProblem($context, (string) $card['source']['artifact_id'], (string) $card['source']['sha256'], $deep);

            if ($cards[$index]['problem'] !== null) {
                $problems[] = $card['name'].': '.$cards[$index]['problem'];

                continue;
            }

            if (in_array($card['source']['artifact_id'], array_column($sources, 'artifact_id'), true)) {
                continue;
            }

            $sources[] = ['position' => count($sources), 'artifact_id' => (string) $card['source']['artifact_id'], 'candidate_id' => array_key_exists('candidate_id', $card['source']) ? $card['source']['candidate_id'] : (string) $card['source']['image_id'], 'sha256' => (string) $card['source']['sha256'], 'kind' => $card['kind'], 'target' => $card['target'], 'image_code' => (string) $card['source']['code']]
                + ($card['kind'] === 'design' ? ['parts' => $card['parts']] : [])
                + ($card['kind'] === 'continuity' ? ['shot' => $card['target'], 'image_id' => (string) $card['source']['image_id'], 'decision_id' => $card['source']['decision_id']] : [])
                + ($card['kind'] === 'character' ? array_intersect_key($card['source'], array_flip(['cell_id', 'cell_code', 'character', 'view', 'items', 'fingerprint', 'master_artifact_id', 'master_sha256', 'decision_id'])) : []);
        }

        if ($cards === []) {
            $problems[] = 'Shot không có nhu cầu ảnh tham chiếu — không có ảnh đầu vào để gửi.';
        }

        $wanted = array_unique(array_filter(array_map(static fn (array $card): ?string => $card['source']['artifact_id'] ?? null, $cards)));

        if (count($wanted) > $context['limit']) {
            $problems[] = 'Shot cần '.count($wanted).' ảnh, vượt giới hạn '.$context['limit'].': '.implode(', ', array_map(static fn (array $card): string => $card['name'], $cards)).'.';
        }

        $slots = [];

        foreach (self::CELLS as $kind) {
            $ofKind = array_values(array_filter($cards, static fn (array $card): bool => $card['kind'] === $kind || ($kind === 'continuity' && $card['kind'] === 'character')));
            $name = $kind === 'continuity' ? ['name' => self::KINDS['continuity'][0].' / '.self::KINDS['character'][0]] : [];
            $slots = [...$slots, ...($ofKind === [] ? [['empty' => true] + $name + $this->card($kind, null, [], [], null, null, [])] : $ofKind)];
        }

        return ['cards' => $cards, 'slots' => $slots, 'sources' => $sources, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $context  what context() returned
     * @param  array<string, string|null>  $seen  what this round of checks already found, by image id
     * @return ?string why the image was made from what is no longer in use: another approved shot plan or prompt, or an input image that is no longer approved or chosen, down the whole chain of keyframes it follows; null while all of it still stands
     */
    public function imageProblem(array $context, VideoDesignImage $image, bool $deep = false, array &$seen = []): ?string
    {
        $id = (string) $image->id;

        if (array_key_exists($id, $seen)) {
            return $seen[$id];
        }

        $seen[$id] = null;
        $spec = (array) $image->prompt_spec_json;
        $shot = $context['shots'][$spec['shot_id'] ?? ''] ?? null;
        $problem = null;

        if (($spec['source_token'] ?? null) != $context['approved']['token']) {
            $problem = 'kế hoạch shot đã đổi so với lúc render';
        } elseif ($shot === null || ($shot['prompts']['image'] ?? null) !== ($spec['prompt'] ?? null)) {
            $problem = 'prompt của shot không còn là prompt đã gửi';
        } else {
            foreach ((array) ($spec['sources'] ?? []) as $entry) {
                $problem = $this->entryProblem($context, (array) $entry, $deep, $seen);

                if ($problem !== null) {
                    break;
                }
            }
        }

        return $seen[$id] = $problem === null ? null : self::STALE.' ('.$problem.')';
    }

    /**
     * @param  array<string, mixed>  $context  what context() returned
     * @param  array<string, string|null>  $seen  what this round of checks already found, by image id
     * @return ?string why an approved keyframe cannot be used by what follows it: its own file is read and found damaged, or it was made from what is no longer in use; null while it stands
     */
    public function keyframeProblem(array $context, VideoDesignImage $image, bool $deep = true, array &$seen = []): ?string
    {
        $artifact = $image->artifacts->firstWhere('id', (string) $image->selected_artifact_id);

        if ($artifact === null) {
            return 'Keyframe không còn ảnh đã chọn';
        }

        return $this->outputProblem($artifact, (array) $image->prompt_spec_json, true) ?? $this->imageProblem($context, $image, $deep, $seen);
    }

    /**
     * @param  array<string, mixed>  $spec  the render spec of the keyframe
     * @return ?string why the file of a keyframe cannot be used: it is gone, its bytes are not the ones stored, it cannot be read as an image or it is not the size asked for; $deep reads the file, once for each artifact in a round of checks, otherwise only the size in the database is compared
     */
    public function outputProblem(VideoArtifact $artifact, array $spec, bool $deep): ?string
    {
        $id = (string) $artifact->id;

        if ($deep && array_key_exists($id, $this->outputs)) {
            return $this->outputs[$id];
        }

        $problem = $this->readOutput($artifact, $spec, $deep);

        if ($deep) {
            $this->outputs[$id] = $problem;
        }

        return $problem;
    }

    /** @param  array<string, mixed>  $spec */
    private function readOutput(VideoArtifact $artifact, array $spec, bool $deep): ?string
    {
        $disk = Storage::disk((string) $artifact->storage_disk);

        if (! $disk->exists((string) $artifact->storage_path)) {
            return 'File ảnh không còn';
        }

        $width = (int) $artifact->width;
        $height = (int) $artifact->height;

        if ($deep) {
            $bytes = (string) $disk->get((string) $artifact->storage_path);

            if (! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
                return 'File ảnh đã bị đổi';
            }

            $read = @getimagesizefromstring($bytes) ?: [];

            if ($read === []) {
                return 'File không đọc được thành ảnh';
            }

            [$width, $height] = [(int) $read[0], (int) $read[1]];
        }

        return $width.'x'.$height === (string) ($spec['size'] ?? '') ? null : 'Ảnh sai kích thước: '.$width.'x'.$height.', yêu cầu '.($spec['size'] ?? '—');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $entry  a line of the manifest of a keyframe
     * @param  array<string, string|null>  $seen
     */
    private function entryProblem(array $context, array $entry, bool $deep, array &$seen): ?string
    {
        $artifactId = (string) ($entry['artifact_id'] ?? '');
        $sha = (string) ($entry['sha256'] ?? '');
        $kind = (string) ($entry['kind'] ?? '');

        if ($kind === 'continuity') {
            $target = (string) ($entry['shot'] ?? '');
            $image = $context['current'][$target] ?? null;

            if ($image === null || (string) $image->selected_artifact_id !== $artifactId) {
                return "keyframe của {$target} đã bị thay hoặc thu hồi";
            }

            $artifact = $image->artifacts->firstWhere('id', $artifactId);

            if ($artifact === null || (string) $artifact->sha256 !== $sha) {
                return "keyframe của {$target} không còn đúng ảnh đã dùng";
            }

            return $this->keyframeProblem($context, $image, $deep, $seen) === null ? null : "keyframe của {$target} có file lỗi hoặc dùng nguồn cũ";
        }

        if ($kind === 'character') {
            $image = $context['people_images'][$entry['cell_id'] ?? ''] ?? null;

            if ($image === null || $image['artifact_id'] !== $artifactId || $image['sha256'] !== $sha || $image['fingerprint'] !== ($entry['fingerprint'] ?? null)
                || $image['master_artifact_id'] !== ($entry['master_artifact_id'] ?? null) || $image['master_sha256'] !== ($entry['master_sha256'] ?? null)) {
                return 'ảnh nhân vật '.($entry['cell_code'] ?? '').' đã bị thay hoặc thu hồi';
            }

            return $this->fileProblem($context, $artifactId, $sha, $deep);
        }

        $problem = match ($kind) {
            'identity' => ($context['sheet']['source']['artifact_id'] ?? null) === $artifactId && ($context['sheet']['source']['sha256'] ?? null) === $sha ? null : 'model sheet đang duyệt đã đổi',
            'design' => isset($context['masters'][$artifactId]) && $context['masters'][$artifactId]['sha256'] === $sha && array_diff((array) ($entry['parts'] ?? []), $context['masters'][$artifactId]['parts']) === [] ? null : 'Reference master không còn hiệu lực',
            'environment' => collect($context['plates'])->contains(static fn (array $plate): bool => $plate['artifact_id'] === $artifactId && $plate['sha256'] === $sha) ? null : 'ảnh nền không còn trong lựa chọn Phase 5',
            default => 'ảnh đầu vào không rõ loại',
        };

        return $problem ?? $this->fileProblem($context, $artifactId, $sha, $deep);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return ?string why the file of the artifact cannot be sent: it is not of the project, its hash differs or it is gone; with $deep the file itself is read and hashed, once for each artifact in a round of checks
     */
    private function fileProblem(array $context, string $artifactId, string $sha, bool $deep): ?string
    {
        $artifact = $context['artifacts'][$artifactId] ?? VideoArtifact::query()->where('project_id', $context['project_id'])->find($artifactId);

        if ($artifact === null) {
            return 'ảnh không thuộc dự án';
        }

        if ((string) $artifact->sha256 !== $sha) {
            return 'hash ảnh trong DB đã lệch';
        }

        $disk = Storage::disk((string) $artifact->storage_disk);

        if (! $disk->exists((string) $artifact->storage_path)) {
            return 'file ảnh không còn';
        }

        if (! $deep) {
            return null;
        }

        $this->files[$artifactId] ??= hash_equals($sha, hash('sha256', (string) $disk->get((string) $artifact->storage_path)));

        return $this->files[$artifactId] ? null : 'file ảnh đã bị đổi';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $needs
     * @return array<string, mixed>
     */
    private function identityCard(array $context, array $needs): array
    {
        return $this->card('identity', null, [], $needs, $context['sheet']['source'], $context['sheet']['problem'], []);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $needs
     * @param  array<string, mixed>  $choice
     * @return list<array<string, mixed>> one card for each master that answers parts of the shot, and one without an image for the parts none answers
     */
    private function designCards(array $context, array $needs, array $choice): array
    {
        $chosen = is_array($choice['design'] ?? null) ? array_values($choice['design']) : null;
        $byMaster = [];
        $missing = [];

        foreach (array_values(array_unique(array_column($needs, 'target'))) as $part) {
            $master = $chosen === null
                ? ($context['part_masters'][$part] ?? null)
                : collect($chosen)->first(static fn (string $id): bool => in_array($part, $context['masters'][$id]['parts'] ?? [], true));

            if ($master === null) {
                $missing[] = (string) $part;
            } else {
                $byMaster[$master][] = (string) $part;
            }
        }

        $partsOf = static fn (array $parts): array => array_values(array_filter($needs, static fn (array $need): bool => in_array($need['target'], $parts, true)));
        $cards = [];

        foreach ($byMaster as $artifactId => $parts) {
            $cards[] = $this->card('design', null, $parts, $partsOf($parts), array_intersect_key($context['masters'][$artifactId], array_flip(['image_id', 'artifact_id', 'sha256', 'code'])), null, $this->masterOptions($context, $parts, (string) $artifactId));
        }

        if ($missing !== []) {
            $problem = $context['reference_problem'] ?? 'chưa có Reference master đã duyệt chứa '.implode(', ', $missing);
            $cards[] = $this->card('design', null, $missing, $partsOf($missing), null, $problem, $this->masterOptions($context, $missing, null));
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $parts
     * @return list<array{artifact_id: string, code: string}> the other approved masters that still answer every part
     */
    private function masterOptions(array $context, array $parts, ?string $current): array
    {
        $options = [];

        foreach ($context['masters'] as $artifactId => $master) {
            if ((string) $artifactId !== $current && array_diff($parts, $master['parts']) === []) {
                $options[] = ['artifact_id' => (string) $artifactId, 'code' => $master['code']];
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $needs
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    private function environmentCard(array $context, array $needs, array $choice): array
    {
        $target = $needs[0]['target'] ?? null;
        $chosen = is_string($choice['environment'] ?? null) ? $choice['environment'] : null;
        $problem = null;

        if ($chosen !== null) {
            $background = collect($context['plates'])->search(static fn (array $plate): bool => $plate['artifact_id'] === $chosen);
            $source = $background === false ? null : $context['plates'][$background];
            $target = $background === false ? $target : $background;
            $problem = $source === null ? 'ảnh nền đã chọn cho lượt này không còn trong lựa chọn Phase 5' : null;
        } elseif ($target === null) {
            $source = null;
            $problem = 'Chờ nền bổ sung — chọn một ảnh nền đã chọn ở Phase 5';
        } else {
            $source = $context['plates'][$target] ?? null;
            $problem = $source === null ? "{$target} chưa có ảnh nền đã chọn ở Phase 5" : null;
        }

        $options = [];

        foreach ($context['plates'] as $background => $plate) {
            if ($plate['artifact_id'] !== ($source['artifact_id'] ?? null)) {
                $options[] = ['artifact_id' => $plate['artifact_id'], 'code' => $background.' · '.$plate['code']];
            }
        }

        return $this->card('environment', is_string($target) ? $target : null, [], $needs, $source, $problem, $options);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $need
     * @return array<string, mixed>
     */
    private function continuityCard(array $context, array $need, bool $deep): array
    {
        $target = (string) $need['target'];
        $image = $context['current'][$target] ?? null;

        if ($image === null) {
            return $this->card('continuity', $target, [], [$need], null, "Chờ keyframe đã duyệt của {$target}", []);
        }

        $artifact = $image->artifacts->firstWhere('id', (string) $image->selected_artifact_id);
        $seen = [];

        if ($artifact === null || $this->keyframeProblem($context, $image, $deep, $seen) !== null) {
            return $this->card('continuity', $target, [], [$need], null, "Keyframe của {$target}: ".self::STALE, []);
        }

        $decision = VideoReviewDecision::query()->where('project_id', $context['project_id'])->where('entity_type', DesignImageStore::SCENE_KEYFRAME_TYPE)->where('entity_id', (string) $artifact->id)->where('decision', 'approved')->orderByDesc('revision')->value('id');

        return $this->card('continuity', $target, [], [$need], ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'code' => (string) $image->image_code, 'decision_id' => $decision === null ? null : (string) $decision], null, []);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>> one card for each person of the shot no keyframe before it already holds: the approved image of its cell, or why the shot waits; a person whose state the data does not settle stays a problem, never another cell
     */
    private function characterCards(array $context, string $shotId): array
    {
        $cards = [];

        foreach ((array) ($context['people']['bindings'][$shotId] ?? []) as $binding) {
            if ($binding['type'] === 'continuity') {
                continue;
            }

            if ($binding['type'] === 'unresolved') {
                $cards[] = $this->card('character', (string) $binding['character'], [], [], null, (string) $binding['problem'], []);

                continue;
            }

            $cell = $context['people_cells'][$binding['cell']];
            $image = $context['people_images'][$binding['cell']] ?? null;
            $worn = $cell['items'] === [] ? 'không' : implode(', ', $cell['items']);
            $source = $image === null ? null : [
                'image_id' => $image['image_id'],
                'candidate_id' => $image['candidate_id'],
                'artifact_id' => $image['artifact_id'],
                'sha256' => $image['sha256'],
                'code' => $image['master_code'].' · '.$cell['code'],
                'cell_id' => $cell['id'],
                'cell_code' => $cell['code'],
                'character' => $cell['character'],
                'view' => $cell['view'],
                'items' => $cell['items'],
                'fingerprint' => $cell['fingerprint'],
                'master_artifact_id' => $image['master_artifact_id'],
                'master_sha256' => $image['master_sha256'],
                'master_code' => $image['master_code'],
                'decision_id' => $image['decision_id'],
            ];
            $cards[] = ['detail' => $cell['character'].' · '.$cell['view'].' · bảo hộ: '.$worn] + $this->card('character', (string) $cell['code'], [], [], $source, $image === null ? 'Chưa có ảnh nhân vật đã duyệt cho '.$cell['code'] : null, []);
        }

        return $cards;
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $needs
     * @param  ?array<string, mixed>  $source
     * @param  list<array{artifact_id: string, code: string}>  $options
     * @return array<string, mixed>
     */
    private function card(string $kind, ?string $target, array $parts, array $needs, ?array $source, ?string $problem, array $options): array
    {
        return [
            'empty' => false,
            'kind' => $kind,
            'name' => self::KINDS[$kind][0].($target === null ? '' : ' '.$target),
            'status' => self::KINDS[$kind][1],
            'target' => $target,
            'parts' => $parts,
            'purposes' => array_values(array_map(static fn (array $need): string => (string) $need['purpose'], $needs)),
            'source' => $source,
            'problem' => $problem,
            'options' => $options,
        ];
    }

    /** @return array{source: ?array{image_id: string, artifact_id: string, sha256: string, code: string}, problem: ?string} the master of the model sheet approved for the current design */
    private function sheet(string $projectId): array
    {
        try {
            $sheet = $this->sheets->approvedSheet($projectId, false);
        } catch (RuntimeException $e) {
            return ['source' => null, 'problem' => $e->getMessage()];
        }

        return ['source' => [
            'image_id' => (string) $sheet['master_image_id'],
            'artifact_id' => (string) $sheet['master_artifact_id'],
            'sha256' => (string) $sheet['master_artifact_sha256'],
            'code' => (string) VideoDesignImage::query()->whereKey($sheet['master_image_id'])->value('image_code'),
        ], 'problem' => null];
    }

    /** @return array{masters: array<string, array{image_id: string, artifact_id: string, sha256: string, code: string, parts: list<string>}>, part_masters: array<string, string>, problem: ?string} every approved Reference master with the parts it still answers, and the master whose approved crop answers each part now */
    private function masters(string $projectId): array
    {
        try {
            $preview = $this->references->preview($projectId);
            $state = $this->references->state($projectId, $preview);
        } catch (RuntimeException $e) {
            return ['masters' => [], 'part_masters' => [], 'problem' => $e->getMessage()];
        }

        $references = array_column($preview['plan']['references'], null, 'id');
        $images = VideoDesignImage::query()->where('project_id', $projectId)->where('image_type', PartsDetailService::MASTER)
            ->where('status', DesignImageStatus::APPROVED->value)->whereNotNull('selected_artifact_id')->with('artifact')->orderByDesc('approved_at')->get();
        $masters = [];
        $byImage = [];

        foreach ($images as $image) {
            $artifact = $image->artifact;

            if ($artifact === null) {
                continue;
            }

            $parts = [];

            foreach ((array) ($image->prompt_spec_json['cells'] ?? []) as $cell) {
                if (($references[$cell['id'] ?? '']['fingerprint'] ?? null) === ($cell['fingerprint'] ?? false)) {
                    $parts[] = (string) $references[$cell['id']]['part'];
                }
            }

            $masters[(string) $artifact->id] = ['image_id' => (string) $image->id, 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'code' => (string) $image->image_code, 'parts' => $parts];
            $byImage[(string) $image->id] = (string) $artifact->id;
        }

        $partMasters = [];

        foreach ($references as $id => $reference) {
            $master = $byImage[$state[$id]['crop']['master_image_id'] ?? ''] ?? null;

            if (($state[$id]['status'] ?? null) === 'approved' && $master !== null) {
                $partMasters[(string) $reference['part']] = $master;
            }
        }

        return ['masters' => $masters, 'part_masters' => $partMasters, 'problem' => null];
    }

    /** @return array<string, array{image_id: string, artifact_id: string, sha256: string, code: string}> the background images chosen at Phase 5, by background; a choice needs no approved status on its image */
    private function plates(string $projectId): array
    {
        $chosen = (array) ($this->locations->selection($projectId)['images'] ?? []);
        $codes = VideoDesignImage::query()->where('project_id', $projectId)->whereIn('id', array_filter(array_column($chosen, 'image_id')))->pluck('image_code', 'id');
        $artifacts = VideoArtifact::query()->where('project_id', $projectId)->whereIn('id', array_filter(array_column($chosen, 'artifact_id')))->get()->keyBy('id');
        $plates = [];

        foreach ($chosen as $background => $image) {
            $artifact = $artifacts->get($image['artifact_id'] ?? null);

            if ($artifact !== null && (string) $artifact->design_image_id === (string) ($image['image_id'] ?? '') && (string) $artifact->sha256 === (string) ($image['sha256'] ?? '')) {
                $plates[(string) $background] = ['image_id' => (string) $image['image_id'], 'artifact_id' => (string) $artifact->id, 'sha256' => (string) $artifact->sha256, 'code' => (string) ($codes[$image['image_id']] ?? '')];
            }
        }

        ksort($plates);

        return $plates;
    }
}
