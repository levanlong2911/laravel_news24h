<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Video\ModelSheet\ModelSheetQaSchema;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

/**
 * Nguon dong bang cua proxy 3D: di tu master model sheet DA DUYET nguoc ve dung luot QA, prompt
 * da gui, bo facts va canonical ma master do dung. Khong lay QA moi nhat hay canonical moi nhat
 * thay cho nguon cua master. Moi mat xich kiem project, trang thai va hash; sai la loi chan.
 *
 * Master cu khong co QA (execution v1) van tra ve nguon de xem, nhung `problems` khac rong va
 * `eligible` = false: khong du dieu kien cua quy trinh moi.
 */
final class YachtProxySource
{
    public function __construct(
        private readonly ModelSheetService $modelSheet,
        private readonly PlanningStageStore $stageStore,
        private readonly DesignImageStore $images,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(string $projectId): array
    {
        // Cong dau vao: master da duyet cua ban thiet ke hien hanh, file master + tam o dung
        // sha256, luot duyet khop, chu de anh xa toi workflow yacht.
        $sheet = $this->modelSheet->approvedSheet($projectId);
        $image = VideoDesignImage::query()->whereKey($sheet['master_image_id'])->where('project_id', $projectId)->first();
        $artifact = VideoArtifact::query()->whereKey($sheet['master_artifact_id'])->where('project_id', $projectId)->first();
        $set = $this->stage($projectId, $sheet['set_id'], PlanningStageName::MODEL_SHEET);
        $design = $this->stage($projectId, $sheet['design_stage_id'], PlanningStageName::YACHT_DESIGN);

        if ($image === null || $artifact === null || $set === null || $design === null) {
            throw new RuntimeException('Chuỗi nguồn proxy đứt: master, artifact, bộ model sheet hoặc bản thiết kế không còn trong dự án.');
        }

        $spec = (array) $image->prompt_spec_json;
        $snapshot = (array) $set->output_json;
        $canonical = $design->output_json['design']['data']['canonical'] ?? null;
        $facts = $snapshot['facts'] ?? null;

        if ((string) $image->selected_artifact_id !== (string) $artifact->id || ($spec['model_sheet_set_id'] ?? null) !== (string) $set->id) {
            throw new RuntimeException('Master đã duyệt không trỏ đúng artifact hoặc bộ model sheet của nó.');
        }

        if (! is_array($canonical) || ! is_string($canonical['hash'] ?? null)
            || $canonical['hash'] !== ($set->input_json['design_canonical_hash'] ?? null) || $canonical['hash'] !== ($spec['design_canonical_hash'] ?? null)) {
            throw new RuntimeException('Canonical của bản thiết kế không khớp hash đã lưu trong bộ model sheet và master.');
        }

        // Tinh lai hash noi dung (doc JSON goc dang object de giu so thuc 4.0), cung cach
        // YachtDesignService gan hash: so truong hash thoi khong bat duoc noi dung bi sua.
        $raw = json_decode((string) $design->getRawOriginal('output_json'), false);
        $object = is_object($raw) ? ($raw->design->data->canonical ?? null) : null;

        if (! is_object($object) || ! hash_equals($canonical['hash'], CanonicalJson::hash($object, ['hash']))) {
            throw new RuntimeException('Nội dung canonical không khớp hash của chính nó — bản thiết kế đã bị sửa sau khi lưu.');
        }

        if (! is_array($facts)) {
            throw new RuntimeException('Bộ model sheet của master thuộc luồng cũ, không có bảng facts — không biên dịch được ProxySpec; render và duyệt lại model sheet.');
        }

        if (! hash_equals((string) ($snapshot['facts_sha256'] ?? ''), hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))) {
            throw new RuntimeException('Bảng facts của bộ model sheet không khớp sha256 đã lưu.');
        }

        $prompt = (string) ($spec['prompt'] ?? '');

        if ($prompt === '' || ! hash_equals((string) ($spec['prompt_sha256'] ?? ''), hash('sha256', $prompt))) {
            throw new RuntimeException('Prompt đóng băng của master không khớp SHA đã lưu.');
        }

        [$qa, $approval, $problems, $qaJson] = $this->qa($projectId, $set, $spec, $prompt);
        $crops = array_map(static fn (array $crop): array => [
            'view_id' => $crop['view_id'],
            'artifact_id' => $crop['id'],
            'sha256' => $crop['sha256'],
            'width' => $crop['width'],
            'height' => $crop['height'],
            'url' => $crop['url'],
        ], $this->images->modelSheetCrops($projectId, $artifact));

        $ids = [
            'project_id' => $projectId,
            'master_image_id' => (string) $image->id,
            'master_artifact_id' => (string) $artifact->id,
            'master_sha256' => (string) $artifact->sha256,
            'review_stage_id' => $sheet['review_stage_id'],
            'set_id' => (string) $set->id,
            'set_execution' => (string) ($set->input_json['execution'] ?? ''),
            'facts_sha256' => (string) $snapshot['facts_sha256'],
            'qa_stage_id' => $qa === null ? null : (string) $qa->id,
            'approval_stage_id' => $approval === null ? null : (string) $approval->id,
            'prompt_sha256' => (string) $spec['prompt_sha256'],
            'design_stage_id' => (string) $design->id,
            'canonical_hash' => (string) $canonical['hash'],
            'crops' => array_map(static fn (array $crop): array => ['view_id' => $crop['view_id'], 'sha256' => $crop['sha256']], $crops),
        ];

        return [
            'ids' => $ids,
            'source_sha256' => hash('sha256', (string) json_encode($ids, JSON_UNESCAPED_SLASHES)),
            'eligible' => $problems === [],
            'problems' => $problems,
            'canonical' => $canonical,
            'facts' => $facts,
            'views' => array_values((array) ($snapshot['views'] ?? [])),
            'qa' => $qaJson,
            'prompt' => $prompt,
            'crops' => $crops,
        ];
    }

    /**
     * Luot QA ma master ghi trong prompt_spec_json: dung bo, dat kiem, cung facts, prompt gui di
     * trung prompt QA va da co luot xac nhan prompt. JSON QA lay tu phan hoi goc cua model
     * (raw_response) va phai trung ban da luu: hash prompt chi bao ve chuoi prompt.
     *
     * @param  array<string, mixed>  $spec
     * @return array{0: ?VideoPlanningStage, 1: ?VideoPlanningStage, 2: list<string>, 3: ?array<string, mixed>}
     */
    private function qa(string $projectId, VideoPlanningStage $set, array $spec, string $prompt): array
    {
        if (! is_string($spec['qa_stage_id'] ?? null) || $spec['qa_stage_id'] === '') {
            return [null, null, ['Master render trước khi có bước QA prompt (luồng cũ) — chỉ xem tương thích, không đủ điều kiện quy trình proxy mới; render và duyệt lại model sheet.'], null];
        }

        $qa = $this->stage($projectId, $spec['qa_stage_id'], PlanningStageName::MODEL_SHEET_PROMPT);

        if ($qa === null || ! ModelSheetQaService::ready($qa) || ($qa->input_json['model_sheet_set_id'] ?? null) !== (string) $set->id) {
            throw new RuntimeException('Lượt QA của master không còn, chưa đạt kiểm hoặc thuộc bộ model sheet khác.');
        }

        if (($qa->input_json['facts_sha256'] ?? null) !== ($set->output_json['facts_sha256'] ?? null)) {
            throw new RuntimeException('Lượt QA của master dùng bảng facts khác bộ model sheet.');
        }

        $qaPrompt = (string) $qa->output_json['prompt'];

        if (! hash_equals($qaPrompt, $prompt) || ! hash_equals((string) ($qa->output_json['prompt_sha256'] ?? ''), hash('sha256', $qaPrompt))) {
            throw new RuntimeException('Prompt đã gửi render không trùng prompt của lượt QA.');
        }

        $approval = $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_PROMPT_APPROVAL, [
            'execution' => ModelSheetService::PROMPT_APPROVAL_EXECUTION,
            'qa_stage_id' => (string) $qa->id,
            'prompt_sha256' => hash('sha256', $qaPrompt),
        ]);

        if ($approval === null) {
            throw new RuntimeException('Không tìm thấy lượt xác nhận prompt QA đã dùng render master.');
        }

        [$data, $error] = ModelSheetQaSchema::decode((string) $qa->raw_response);
        $original = $error === null ? json_decode((string) json_encode($data, JSON_PRESERVE_ZERO_FRACTION), true) : null;
        $stored = json_decode((string) json_encode(json_decode((string) $qa->getRawOriginal('output_json'), false)->qa ?? null, JSON_PRESERVE_ZERO_FRACTION), true);

        if (! is_array($original) || $original !== $stored) {
            throw new RuntimeException('JSON QA đã lưu không trùng phản hồi gốc của model — lượt QA đã bị sửa sau khi lưu.');
        }

        return [$qa, $approval, [], $original];
    }

    private function stage(string $projectId, mixed $id, PlanningStageName $name): ?VideoPlanningStage
    {
        return is_string($id) && $id !== '' ? VideoPlanningStage::query()
            ->whereKey($id)
            ->where('project_id', $projectId)
            ->where('stage', $name->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first() : null;
    }
}
