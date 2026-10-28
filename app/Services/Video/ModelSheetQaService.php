<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\AiPhase;
use App\Models\VideoPlanningStage;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\Llm\JsonAnswer;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\ModelSheet\ModelSheetPromptCompiler;
use App\Video\ModelSheet\ModelSheetQaSchema;
use App\Video\ModelSheet\ModelSheetQaValidator;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * QA va bien tap noi dung prompt model sheet bang Sonnet 5.5 (stage MODEL_SHEET_PROMPT).
 *
 * Chi chay khi nguoi dung bam. Mot ket qua QA hop le duoc dung lai cho moi lan render cua
 * cung bo model sheet; doi nguon (thiet ke, prompt QA, schema, compiler, model) la dau vao
 * moi. Luot goi thanh cong ve ky thuat nhung co loi kiem hoac finding blocking van duoc luu
 * kem usage, chi la chua san sang render.
 */
final class ModelSheetQaService
{
    public function __construct(
        private readonly PlanningStageStore $stageStore,
    ) {}

    /**
     * Dau vao QA cua mot bo model sheet: moi thu anh huong ket qua.
     *
     * @return array<string, mixed>
     */
    public function input(VideoPlanningStage $set): array
    {
        $config = (array) config('video.superyacht.model_sheet.qa');
        $snapshot = (array) $set->output_json;

        return [
            'execution' => (string) $config['execution'],
            'model_sheet_set_id' => (string) $set->id,
            'facts_sha256' => (string) $snapshot['facts_sha256'],
            'qa_phase_id' => (string) $snapshot['qa_phase']['id'],
            'qa_phase_revision' => (int) $snapshot['qa_phase']['revision'],
            'qa_prompt_sha256' => (string) $snapshot['qa_phase']['sha256'],
            'schema_version' => ModelSheetQaValidator::VERSION,
            'compiler_version' => ModelSheetPromptCompiler::VERSION,
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'schema_transport' => self::transport(),
            'schema_sha256' => ModelSheetQaSchema::sha256(),
            'structure_check' => ModelSheetQaSchema::VERSION,
        ];
    }

    /** prompt-json (mac dinh): schema trong prompt, may chu kiem bang Opis; api-grammar: ep grammar o API (thu nghiem). */
    public static function transport(): string
    {
        return self::enforced() ? 'api-grammar' : 'prompt-json';
    }

    private static function enforced(): bool
    {
        return (bool) config('video.superyacht.model_sheet.qa.enforce_schema');
    }

    /**
     * Prompt he thong: prompt QA cua DB; khi API khong ep grammar thi noi them schema dau ra (mot
     * nguon: ModelSheetQaValidator::SCHEMA) de model tra dung khung, may chu kiem lai.
     */
    public static function system(AiPhase $phase): string
    {
        $prompt = (string) $phase->prompt_content;

        return self::enforced() ? $prompt : rtrim($prompt)."

### Output JSON Schema

Return exactly one JSON object, with no text or code fence around it, that validates against this JSON Schema (".ModelSheetQaValidator::VERSION."):

"
            .json_encode(ModelSheetQaValidator::SCHEMA, JSON_UNESCAPED_SLASHES)."
";
    }

    /** Luot QA moi nhat cua dung dau vao (moi trang thai), hoac null. */
    public function current(string $projectId, VideoPlanningStage $set): ?VideoPlanningStage
    {
        [$latest, $matches] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::MODEL_SHEET_PROMPT, $this->input($set), skipOrphans: true);

        if ($matches) {
            return $latest;
        }

        return $this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_PROMPT, $this->input($set));
    }

    public static function ready(?VideoPlanningStage $stage): bool
    {
        return $stage !== null
            && $stage->status === VideoPlanningStageStatus::SUCCEEDED->value
            && ($stage->output_json['ready'] ?? false) === true
            && is_string($stage->output_json['prompt'] ?? null);
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string} reason: ready|not_ready|cached|...
     */
    public function run(string $projectId, VideoPlanningStage $set, bool $retry): array
    {
        try {
            $phase = $this->phase($set);
            [$design, $dna] = $this->source($set);
            $client = app('video.model_sheet_qa.client');
        } catch (Throwable $e) {
            Log::error('model sheet qa: input unavailable, no model call made', ['project_id' => $projectId, 'exception' => $e]);

            return [null, 'Chưa gọi Sonnet: '.$e->getMessage()];
        }

        $snapshot = (array) $set->output_json;

        if (($snapshot['blocking'] ?? []) !== []) {
            return [null, 'Chưa gọi Sonnet: '.implode(' ', (array) $snapshot['blocking'])];
        }

        $input = $this->input($set);
        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::MODEL_SHEET_PROMPT,
            $input,
            static fn (VideoPlanningStage $served): bool => $retry && ! self::ready($served),
        );

        if ($token === null) {
            return match (true) {
                $reason === 'already_succeeded' && ! self::ready($stage) => [$stage, 'QA đã lưu cho đúng nguồn này nhưng chưa đạt kiểm — bấm "QA lại" để chạy lượt mới. Không gọi Sonnet.'],
                $reason === 'already_succeeded' => [$stage, 'cached'],
                $reason === 'claimed_by_other' => [null, 'Đang có một lượt QA model sheet chạy cho dự án này — đợi xong rồi thử lại.'],
                default => [null, 'Không mở được lượt QA ('.$reason.').'],
            };
        }

        $config = (array) config('video.superyacht.model_sheet.qa');
        $version = 'spec-v6.3-'.$phase->phase_code.'@rev'.$phase->revision;
        $raw = '';
        $usage = ['model' => (string) $config['model'], 'instruction_version' => $version];
        $pricing = ['pricing' => 'unpriced', 'pricing_version' => null];
        $stageId = (string) $stage->id;

        try {
            /** @var AnthropicStructuredOutputClient $client */
            $response = $client->create(
                (string) $config['model'],
                self::system($phase),
                [['role' => 'user', 'content' => $this->message($snapshot, $design, $dna)]],
                self::enforced() ? ModelSheetQaValidator::SCHEMA : [],
                (int) $config['max_tokens'],
                fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state),
            );
            [$usage, $pricing] = self::accounting($response, $config, $version);
            $raw = $response->rawText;
            $output = $this->output($set, $raw, $design, $dna) + ['request_id' => $response->requestId];

            if (! $this->stageStore->finishSucceeded((string) $stage->id, $token, $raw, $output, $usage, $pricing)) {
                return [null, 'Lượt QA bị giành mất khi lưu — tải lại trang. Usage của lượt này nằm trong log.'];
            }

            return [VideoPlanningStage::query()->find($stage->id), $output['ready'] ? 'ready' : 'not_ready'];
        } catch (Throwable $e) {
            Log::error('model sheet qa: the run failed', ['stage_id' => $stage->id, 'exception' => $e]);
            $billed = $e instanceof AnthropicRefusalException || $e instanceof AnthropicTruncatedOutputException || $e instanceof AnthropicRequestException ? $e->response : null;

            if ($billed !== null) {
                [$usage, $pricing] = self::accounting($billed, $config, $version);
            }

            $message = $e->getMessage();

            if ($billed !== null && ! $billed->usageComplete) {
                $message .= ' Chưa nhận usage cuối: đã biết '.$billed->inputTokens.' token vào và '.$billed->outputTokens.' token ra — chi phí ghi nhận $'.number_format((float) $usage['cost_usd'], 4, '.', '').' chỉ là mức tối thiểu, phí thực tế có thể cao hơn.';
            }

            if (self::enforced() && str_contains(strtolower($message), 'grammar')) {
                $message = 'Đang bật MODEL_SHEET_QA_ENFORCE_SCHEMA=true (ép grammar tại API) và Anthropic từ chối schema: '.$message.' Tắt cờ này để dùng chế độ mặc định (schema trong prompt, máy chủ kiểm bằng Opis).';
            }

            $this->stageStore->finishFailed((string) $stage->id, $token, $message, $usage, $raw !== '' ? $raw : (string) ($billed?->rawText ?? ''), ['request_id' => $billed?->requestId, 'schema_transport' => self::transport(), 'usage_complete' => $billed?->usageComplete], $pricing);

            return [null, 'QA thất bại: '.$message];
        }
    }

    /**
     * Kiem lai ket qua da luu va ghep prompt (dung khi luu va khi can kiem lai bang code moi).
     *
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $dna
     * @return array<string, mixed>
     */
    public function output(VideoPlanningStage $set, string $raw, array $design, array $dna): array
    {
        $snapshot = (array) $set->output_json;
        $config = (array) config('video.superyacht.model_sheet');
        $checks = ['parse' => 'failed', 'schema' => 'skipped', 'business' => 'skipped', 'findings' => 'skipped', 'compile' => 'skipped'];
        $validation = ['ok' => false, 'errors' => [], 'blocking' => [], 'notes' => []];
        $schemaErrors = [];
        $qa = null;
        $prompt = null;

        // 1. Doc JSON nghiem ngat, giu object/array. 2. Kiem toan bo schema bang Opis TRUOC moi ep kieu.
        [$data, $parseError] = ModelSheetQaSchema::decode($raw);

        if ($parseError !== null) {
            $validation['errors'][] = 'Đọc JSON: '.$parseError;
        } else {
            $checks['parse'] = 'passed';
            $schemaErrors = ModelSheetQaSchema::errors($data);
            $checks['schema'] = $schemaErrors === [] ? 'passed' : 'failed';
            $validation['errors'] = array_map(static fn (string $e): string => 'Cấu trúc: '.$e, $schemaErrors);
        }

        // 3. Chi khi dung schema moi chuyen sang mang va kiem nghiep vu; 4. roi moi ghep prompt.
        if ($checks['schema'] === 'passed') {
            $qa = json_decode((string) json_encode($data, JSON_PRESERVE_ZERO_FRACTION), true);
            $validator = new ModelSheetQaValidator((array) $snapshot['facts'], $design, $dna, array_column((array) $snapshot['views'], 'id'), (int) $config['qa']['negatives_target'], array_values((array) $config['master']['detail_views']));
            $validation = $validator->validate($qa);
            $checks['business'] = $validation['errors'] === [] ? 'passed' : 'failed';
            $checks['findings'] = $validation['blocking'] === [] ? 'clear' : 'blocking';

            if ($checks['business'] === 'passed') {
                try {
                    $prompt = (new ModelSheetPromptCompiler($config, $validator->facts()))->compile($qa, (array) $snapshot['views'], ($snapshot['layout_guide'] ?? false) === true, (array) $snapshot['facts']['ms8']);
                    $checks['compile'] = 'passed';
                } catch (RuntimeException $e) {
                    $validation['errors'][] = $e->getMessage();
                    $validation['ok'] = false;
                    $checks['compile'] = 'failed';
                }
            }
        }

        $sha = is_string($prompt) && trim($prompt) !== '' ? hash('sha256', $prompt) : null;
        $ready = $checks['parse'] === 'passed' && $checks['schema'] === 'passed' && $checks['business'] === 'passed'
            && $checks['findings'] === 'clear' && $checks['compile'] === 'passed' && $sha !== null && hash_equals($sha, hash('sha256', (string) $prompt));

        return [
            'execution' => (string) $config['qa']['execution'],
            'model_sheet_set_id' => (string) $set->id,
            'schema_transport' => self::transport(),
            'schema_version' => ModelSheetQaValidator::VERSION,
            'schema_sha256' => ModelSheetQaSchema::sha256(),
            'structure_check' => ModelSheetQaSchema::VERSION,
            'checks' => $checks + ['ready' => $ready],
            'schema_errors' => $schemaErrors,
            'qa' => is_array($qa) ? $qa : null,
            'validation' => $validation,
            'ready' => $ready,
            'prompt' => $ready ? $prompt : null,
            'prompt_sha256' => $ready ? $sha : null,
            'prompt_chars' => $ready ? mb_strlen((string) $prompt) : null,
        ];
    }

    /**
     * Ban thiet ke va DNA nguon cua bo, kiem dung ban ma bo da chot.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function source(VideoPlanningStage $set): array
    {
        $designStage = VideoPlanningStage::query()->whereKey($set->input_json['design_stage_id'] ?? null)->where('project_id', $set->project_id)->first()
            ?? throw new RuntimeException('Không tìm thấy bản thiết kế nguồn của bộ model sheet.');
        $raw = (string) $designStage->raw_response;

        if (! hash_equals((string) ($set->input_json['design_raw_sha256'] ?? ''), hash('sha256', $raw))) {
            throw new RuntimeException('Bản thiết kế nguồn đã khác bản bộ model sheet chốt.');
        }

        $design = JsonAnswer::object($raw) ?? throw new RuntimeException('Không đọc được JSON bản thiết kế nguồn.');
        $dnaStage = VideoPlanningStage::query()->whereKey($designStage->input_json['dna_stage_id'] ?? null)->where('project_id', $set->project_id)->first();
        $dna = $dnaStage === null ? [] : (array) (JsonAnswer::object((string) ($dnaStage->output_json['raw_text'] ?? ''))['dna'] ?? []);

        return [$design, $dna];
    }

    private function phase(VideoPlanningStage $set): AiPhase
    {
        $ref = (array) (($set->output_json ?? [])['qa_phase'] ?? []);
        $phase = AiPhase::query()->find($ref['id'] ?? null) ?? throw new RuntimeException('Không tìm thấy prompt QA model sheet mà bộ đã chốt.');

        if ($phase->status !== AiPhase::PUBLISHED || ! hash_equals((string) ($ref['sha256'] ?? ''), (string) $phase->content_sha256)
            || ! hash_equals((string) $phase->content_sha256, hash('sha256', (string) $phase->prompt_content))) {
            throw new RuntimeException('Prompt QA model sheet không còn khớp hash bộ đã chốt.');
        }

        return $phase;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $design
     * @param  array<string, mixed>  $dna
     */
    private function message(array $snapshot, array $design, array $dna): string
    {
        $facts = (array) $snapshot['facts'];
        $candidates = (array) $facts['candidates'];
        $cameras = (array) config('video.superyacht.model_sheet.cameras');

        return (string) json_encode([
            'design' => $design,
            'dna' => $dna,
            // Chi muc day du moi so: "F12 | path | property | render [| formula (not printed): …]".
            // Cong thuc la cot rieng, khong thuoc render nen khong vao prompt.
            'facts' => implode("\n", array_map(static fn (array $f): string => $f['id'].' | '.$f['path'].' | '.$f['property'].' | '.$f['render'].(isset($f['formula']) ? ' | formula (not printed): '.$f['formula'] : ''), (array) $facts['facts'])),
            'entities' => array_map(static fn (array $e): array => array_intersect_key($e, array_flip(['id', 'kind', 'path', 'name', 'from', 'to', 'side', 'side_source', 'level', 'level_source', 'full_beam', 'facing', 'near_side', 'pair', 'facts', 'range_source'])), (array) $facts['entities']),
            'views' => array_map(static fn (array $v): array => [
                'id' => $v['id'],
                'label' => $v['view'],
                'camera' => ModelSheetPromptCompiler::camera($cameras, $v['id'], (array) $facts['ms8']),
                'sees_only' => ModelSheetFacts::VIEW_SIDES[$v['id']] ?? null,
                'detail_view' => in_array($v['id'], (array) config('video.superyacht.model_sheet.master.detail_views'), true),
                'candidates' => $candidates[$v['id']] ?? [],
            ], (array) $snapshot['views']),
            'ms8' => $facts['ms8'],
            'state_sources' => $facts['state_sources'],
            'occluders' => $facts['occluders'] ?? [],
            'coverage' => $facts['coverage'],
            'source_conflicts' => $facts['conflicts'],
            'draft' => $facts['draft'],
            'negatives_target' => (int) config('video.superyacht.model_sheet.qa.negatives_target'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: array{pricing: string, pricing_version: ?string}}
     */
    private static function accounting(AnthropicStructuredOutputResponse $response, array $config, string $version): array
    {
        $rates = (array) $config['pricing'];
        $priced = str_starts_with($response->model, (string) $config['model']) && $response->inputTokens > 0;
        $cost = $priced ? round(($response->inputTokens * (float) $rates['input_usd_per_mtok'] + $response->outputTokens * (float) $rates['output_usd_per_mtok']) / 1000000, 6) : 0.0;

        return [[
            'model' => (string) $config['model'],
            'provider_model' => $response->model,
            'instruction_version' => $version,
            'tokens_in' => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'cost_usd' => $cost,
            'request_id' => $response->requestId,
        ], ['pricing' => $priced ? 'estimated' : 'unpriced', 'pricing_version' => $priced ? (string) $rates['version'] : null]];
    }
}
