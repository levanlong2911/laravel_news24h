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
use App\Video\ModelSheet\ModelSheetQaSchema;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Prompt model sheet bang Sonnet 5.5 (stage MODEL_SHEET_PROMPT): Sonnet doc ban thiet ke day du mot
 * lan cung ban nhap, chi muc, camera va quy tac co dinh, viet toan bo `final_prompt` va tu kiem.
 *
 * Chi chay khi nguoi dung bam. Code chi kiem ky thuat (doc duoc, dung cau truc, prompt khong rong;
 * phan hoi bi cat do het token la loi cua client) roi luu nguyen van prompt, ghi chu, usage va hash
 * — khong ghep, khong noi them, khong viet lai, khong cham chat luong noi dung. Mot ket qua dat duoc
 * dung lai cho moi lan render cua cung bo model sheet; doi nguon (thiet ke, prompt QA, schema, model)
 * la dau vao moi.
 */
final class ModelSheetQaService
{
    public const MESSAGE_VERSION = 'model-sheet-qa-message-v2';

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
            'message_version' => self::MESSAGE_VERSION,
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
     * nguon: ModelSheetQaSchema::SCHEMA) de model tra dung khung, may chu kiem lai.
     */
    public static function system(AiPhase $phase): string
    {
        $prompt = (string) $phase->prompt_content;

        return self::enforced() ? $prompt : rtrim($prompt)."

### Output JSON Schema

Return exactly one JSON object, with no text or code fence around it, that validates against this JSON Schema (".ModelSheetQaSchema::OUTPUT."):

"
            .json_encode(ModelSheetQaSchema::SCHEMA, JSON_UNESCAPED_SLASHES)."
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
            && is_string($stage->output_json['final_prompt'] ?? null)
            && trim($stage->output_json['final_prompt']) !== '';
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string} reason: ready|not_ready|cached|...
     */
    public function run(string $projectId, VideoPlanningStage $set, bool $retry): array
    {
        try {
            $phase = $this->phase($set);
            $design = $this->source($set);
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
                $reason === 'already_succeeded' && ! self::ready($stage) => [$stage, 'Lượt prompt đã lưu cho đúng nguồn này nhưng phản hồi chưa đọc được — bấm "QA lại" để chạy lượt mới. Không gọi Sonnet.'],
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
                [['role' => 'user', 'content' => self::message($snapshot, $design)]],
                self::enforced() ? ModelSheetQaSchema::SCHEMA : [],
                (int) $config['max_tokens'],
                fn (array $state): bool => $this->stageStore->recordStream($stageId, $token, $state),
            );
            [$usage, $pricing] = self::accounting($response, $config, $version);
            $raw = $response->rawText;
            $output = $this->output($set, $raw) + ['request_id' => $response->requestId];

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
     * Doc phan hoi theo cau truc da chot va giu nguyen van final_prompt; khong kiem noi dung.
     *
     * @return array<string, mixed>
     */
    public function output(VideoPlanningStage $set, string $raw): array
    {
        $checks = ['parse' => 'failed', 'schema' => 'skipped', 'prompt' => 'skipped'];
        $errors = [];
        $answer = null;

        [$data, $parseError] = ModelSheetQaSchema::decode($raw);

        if ($parseError !== null) {
            $errors[] = 'Đọc JSON: '.$parseError;
        } else {
            $checks['parse'] = 'passed';
            $schemaErrors = ModelSheetQaSchema::errors($data);
            $checks['schema'] = $schemaErrors === [] ? 'passed' : 'failed';
            $errors = array_map(static fn (string $e): string => 'Cấu trúc: '.$e, $schemaErrors);
        }

        if ($checks['schema'] === 'passed') {
            $answer = ['final_prompt' => (string) $data->final_prompt, 'repairs' => array_values((array) $data->repairs), 'findings' => array_values((array) $data->findings)];
            $checks['prompt'] = trim($answer['final_prompt']) === '' ? 'failed' : 'passed';

            if ($checks['prompt'] === 'failed') {
                $errors[] = 'final_prompt rỗng.';
            }
        }

        $ready = $checks['prompt'] === 'passed';
        $prompt = $ready ? $answer['final_prompt'] : null;

        return [
            'execution' => (string) config('video.superyacht.model_sheet.qa.execution'),
            'model_sheet_set_id' => (string) $set->id,
            'schema_transport' => self::transport(),
            'schema_sha256' => ModelSheetQaSchema::sha256(),
            'structure_check' => ModelSheetQaSchema::VERSION,
            'checks' => $checks + ['ready' => $ready],
            'errors' => $errors,
            'repairs' => $answer['repairs'] ?? [],
            'findings' => $answer['findings'] ?? [],
            'ready' => $ready,
            'final_prompt' => $prompt,
            'prompt_sha256' => $prompt === null ? null : hash('sha256', $prompt),
            'prompt_chars' => $prompt === null ? null : mb_strlen($prompt),
        ];
    }

    /**
     * Ban thiet ke nguon cua bo, kiem dung ban ma bo da chot (raw JSON hash).
     *
     * @return array<string, mixed>
     */
    public function source(VideoPlanningStage $set): array
    {
        $designStage = VideoPlanningStage::query()->whereKey($set->input_json['design_stage_id'] ?? null)->where('project_id', $set->project_id)->first()
            ?? throw new RuntimeException('Không tìm thấy bản thiết kế nguồn của bộ model sheet.');
        $raw = (string) $designStage->raw_response;

        if (! hash_equals((string) ($set->input_json['design_raw_sha256'] ?? ''), hash('sha256', $raw))) {
            throw new RuntimeException('Bản thiết kế nguồn đã khác bản bộ model sheet chốt.');
        }

        return JsonAnswer::object($raw) ?? throw new RuntimeException('Không đọc được JSON bản thiết kế nguồn.');
    }

    /**
     * Thong so camera da chot va quy uoc phuong vi.
     *
     * @return array{convention: string, views: array<string, array<string, mixed>>}
     */
    public static function cameras(): array
    {
        return [
            'convention' => (string) config('video.superyacht.model_sheet.camera_convention'),
            'views' => (array) config('video.superyacht.model_sheet.cameras'),
        ];
    }

    /**
     * Bo cuc, khung hinh, trinh bay va quy tac co dinh tu config; Sonnet dua vao prompt dung mot lan.
     *
     * @param  array<string, mixed>  $snapshot  output_json cua bo model sheet
     * @return array<string, mixed>
     */
    public static function fixedRules(array $snapshot): array
    {
        $config = (array) config('video.superyacht.model_sheet');
        $master = (array) $config['master'];
        $text = (array) $master['prompt'];
        [$width, $height] = array_map('intval', explode('x', (string) $master['size']));
        $views = array_values((array) $snapshot['views']);
        usort($views, static fn (array $a, array $b): int => [$a['row'], $a['column']] <=> [$b['row'], $b['column']]);

        return array_filter([
            'master' => ['width' => $width, 'height' => $height, 'rows' => (int) $master['rows'], 'columns' => (int) $master['columns'], 'cell_width' => (int) $master['cell']['width'], 'cell_height' => (int) $master['cell']['height']],
            'layout' => self::fill((string) $text['layout'], [
                'width' => $width, 'height' => $height, 'rows' => $master['rows'], 'columns' => $master['columns'],
                'cell_width' => $master['cell']['width'], 'cell_height' => $master['cell']['height'],
            ]),
            'panels' => array_map(static fn (array $view): string => self::fill((string) $text['panel'], [
                'row' => $view['row'], 'column' => $view['column'],
                'x' => $view['x'], 'x_end' => $view['x'] + $view['width'], 'y' => $view['y'], 'y_end' => $view['y'] + $view['height'],
                'id' => $view['id'], 'view' => $view['view'],
            ]), $views),
            'framing' => (string) $text['framing'],
            'detail_framing' => array_map(static fn (string $id): string => self::fill((string) $text['detail_framing'], ['id' => $id]), array_values((array) $master['detail_views'])),
            'rules' => [...array_map('strval', (array) $text['rules']), (string) $config['occlusion']],
            'presentation' => (string) $text['presentation'],
            'studio' => (string) $config['suffix'],
            'layout_guide' => ($snapshot['layout_guide'] ?? false) === true ? (string) $text['guide_caption'] : null,
            'max_chars' => (int) ($text['max_chars'] ?? 0),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Thong diep gui Sonnet: nam nhom dau vao, thiet ke day du mot lan.
     *
     * @param  array<string, mixed>  $snapshot  output_json cua bo model sheet
     * @param  array<string, mixed>  $design
     */
    public static function message(array $snapshot, array $design): string
    {
        $facts = (array) $snapshot['facts'];

        return (string) json_encode([
            'design_source' => $design,
            'draft' => $facts['draft'],
            'index' => $facts['index'],
            'cameras' => self::cameras(),
            'fixed_rules' => self::fixedRules($snapshot),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function phase(VideoPlanningStage $set): AiPhase
    {
        $ref = (array) (($set->output_json ?? [])['qa_phase'] ?? []);
        $phase = AiPhase::query()->find($ref['id'] ?? null) ?? throw new RuntimeException('Không tìm thấy prompt QA model sheet mà bộ đã chốt.');

        if ($phase->status !== AiPhase::PUBLISHED || ! hash_equals((string) ($ref['sha256'] ?? ''), (string) $phase->content_sha256)
            || ! hash_equals((string) $phase->content_sha256, hash('sha256', (string) $phase->prompt_content))) {
            throw new RuntimeException('Prompt QA model sheet không còn khớp hash bộ đã chốt.');
        }

        if (($problem = self::contractProblem($phase)) !== null) {
            throw new RuntimeException($problem);
        }

        return $phase;
    }

    /**
     * @param  AiPhase  $phase  a revision of the model sheet prompt
     * @return ?string why that prompt does not describe the message code sends or the answer code reads; checked before any model call
     */
    public static function contractProblem(AiPhase $phase): ?string
    {
        foreach (['Input format: `'.self::MESSAGE_VERSION.'`', 'Output format: `'.ModelSheetQaSchema::OUTPUT.'`'] as $marker) {
            if (! str_contains((string) $phase->prompt_content, $marker)) {
                return "Prompt {$phase->phase_code} revision {$phase->revision} không khai {$marker} mà code đang dùng — cần phát hành revision prompt tương thích trước, không gọi Sonnet.";
            }
        }

        return null;
    }

    /** @param array<string, int|string> $values */
    private static function fill(string $template, array $values): string
    {
        return strtr($template, array_combine(
            array_map(static fn (string $key): string => '{'.$key.'}', array_keys($values)),
            array_map('strval', array_values($values)),
        ));
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
