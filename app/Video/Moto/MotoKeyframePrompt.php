<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Script\CheckedWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Support\CanonicalJson;

final class MotoKeyframePrompt implements CheckedWorkflow
{
    public const CONTRACT = 'moto/MotoKeyframePrompt@1.0.1';

    /** @var list<string> every output contract this check reads: the current one, and the one earlier runs were written to, whose fields are the same */
    public const CONTRACTS = ['moto/MotoKeyframePrompt@1.0.0', self::CONTRACT];

    public const CHECK_VERSION = 'moto-keyframe-prompt-check-v1';

    /** @var string what a keyframe pins as the surface rule of a prompt the model wrote from the design, where no rule of code compiled its surface words */
    public const SURFACE_RULE = 'written-by-model';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    /** @var list<string> the fields of a stored shot that hold for the whole shot; the fields of a moment each go to the group of their moment, once */
    private const SHOT_FIELDS = ['id', 'scene', 'state', 'purpose', 'coverage', 'reveal', 'camera', 'parts', 'off_frame', 'people', 'environment', 'places', 'set_brief', 'work', 'work_items', 'held_parts', 'absent_parts', 'limits'];

    /** @var string what the payload says of the fields that come after the opening frame */
    public const LATER_NOTE = 'These fields describe later events or outgoing continuity, not the opening frame. They are no independent evidence for placing anything in the opening frame.';

    /** @var list<string> the fields of a script beat the model reads; the cards stay with the film and the sound with the clip */
    private const BEAT_FIELDS = ['id', 'act', 'state', 'time_jump', 'purpose', 'visual', 'location', 'time_light', 'reveal', 'design_refs', 'in_use', 'configurations', 'situation', 'link'];

    /** @var list<string> the fields of the motorcycle as a whole the model reads */
    private const MOTORCYCLE_FIELDS = ['name', 'silhouette', 'symmetry', 'profile_curve', 'palette', 'materials', 'markings', 'lighting_signature'];

    public function format(): string
    {
        return MotoContract::KEYFRAME_PROMPT_FORMAT;
    }

    public function inputsVersion(): string
    {
        return MotoContract::KEYFRAME_PROMPT_INPUT;
    }

    public function checkVersion(): string
    {
        return self::CHECK_VERSION;
    }

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, schema_heading: string, image_max_edge: int, image_max_bytes: int, image_jpeg_quality: int, estimate_output_tokens: int, max_prompt_chars: int} */
    public function settings(): array
    {
        $config = (array) config('video.moto.keyframe_prompt');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'schema_heading' => (string) $config['schema_heading'],
            'image_max_edge' => (int) $config['image_max_edge'],
            'image_max_bytes' => (int) $config['image_max_bytes'],
            'image_jpeg_quality' => (int) $config['image_jpeg_quality'],
            'estimate_output_tokens' => (int) $config['estimate_output_tokens'],
            'max_prompt_chars' => (int) $config['max_prompt_chars'],
            'composition_policy' => (array) $config['composition_policy'],
        ];
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $schemas
     */
    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.keyframe_prompt.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * @param  array<string, mixed>  $shot  the stored shot of the approved plan
     * @param  array<string, mixed>  $pinned  the check context pinned with the shot run: its places, backgrounds, configurations, limits and state records
     * @param  array<string, mixed>  $design  the data of the design the script was written from
     * @param  array<string, mixed>  $script  the data of the approved script
     * @param  list<array<string, mixed>>  $images  the reference images the render sends, in order, each with its position from 1
     * @param  array<string, mixed>  $policy  how the render composes the frame from the images, set by Laravel and pinned with the sources; the aspect ratio, the image model, the quality and the size are chosen for the render and stay out of the prompt
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what the model reads for the first frame of the shot, from the records of the shot alone, every record taken whole by its id, and what the check reads back
     */
    public static function inputs(array $shot, array $pinned, array $design, array $script, array $images, array $policy, string $fingerprint, int $maxPromptChars): array
    {
        $state = (string) ($shot['state'] ?? '');
        $configurations = array_column((array) ($pinned['configurations'] ?? []), null, 'id');
        $partIds = self::partIds($shot, $configurations);
        $designParts = array_column((array) ($design['parts'] ?? []), null, 'id');
        $named = array_values(array_unique([...$partIds, ...array_values(array_filter(array_map(static fn (string $id): ?string => $designParts[$id]['parent'] ?? null, $partIds), 'is_string'))]));
        $touches = static fn (array $ids): bool => array_intersect($ids, $partIds) !== [];
        $places = array_values(array_unique((array) ($shot['places'] ?? [])));
        $sent = collect($images)->firstWhere('kind', 'environment');
        $background = $sent === null ? null : ($sent['background'] ?? null);
        $beats = array_values(array_unique(array_map(static fn (array $item): string => (string) $item['beat'], (array) ($shot['coverage'] ?? []))));
        $characters = (array) ($script['characters'] ?? []);
        $work = array_values(array_unique([...(array) ($shot['work'] ?? []), ...array_column((array) ($shot['work_items'] ?? []), 'id')]));
        $limits = array_values(array_filter((array) ($pinned['limits'] ?? []), static fn (array $limit): bool => in_array($limit['id'] ?? null, (array) ($shot['limits'] ?? []), true)));

        $payload = [
            'input_format' => MotoContract::KEYFRAME_PROMPT_INPUT,
            'shot_id' => (string) $shot['id'],
            'source_fingerprint' => $fingerprint,
            'shot' => array_intersect_key($shot, array_flip(self::SHOT_FIELDS)),
            'opening_frame' => [
                'first_frame' => $shot['first_frame'] ?? null,
                'carried_from' => $shot['carried_from'] ?? null,
                'configurations_at_start' => self::configurations((array) ($shot['configurations']['start'] ?? []), $configurations),
            ],
            'later_in_clip' => [
                'note' => self::LATER_NOTE,
                'action' => $shot['action'] ?? null,
                'result' => $shot['result'] ?? null,
                'end_frame' => $shot['end_frame'] ?? null,
                'changes' => array_values((array) ($shot['continuity']['changes'] ?? [])),
                'carries_to_next_shot' => array_values((array) ($shot['continuity']['carries'] ?? [])),
                'configurations_at_end' => self::configurations((array) ($shot['configurations']['end'] ?? []), $configurations),
            ],
            'beats' => array_values(array_map(static fn (array $beat): array => array_intersect_key($beat, array_flip(self::BEAT_FIELDS)), array_filter((array) ($script['beats'] ?? []), static fn (array $beat): bool => in_array($beat['id'] ?? null, $beats, true)))),
            'places' => array_values(array_filter((array) ($pinned['places'] ?? []), static fn (array $place): bool => in_array($place['id'] ?? null, $places, true))),
            'background' => is_string($background) && isset($pinned['backgrounds'][$background]) ? ['id' => $background, 'name' => (string) ($sent['background_name'] ?? ''), 'chosen_by' => (string) ($sent['chosen_by'] ?? 'plan')] + (array) $pinned['backgrounds'][$background] : null,
            'characters' => array_map(static fn (array $person): array => [
                'character' => (string) $person['character'],
                'view' => $person['view'] ?? null,
                'description' => $characters[$person['character']] ?? null,
            ], (array) ($shot['people'] ?? [])),
            'design' => [
                'motorcycle' => array_intersect_key((array) ($design['motorcycle'] ?? []), array_flip(self::MOTORCYCLE_FIELDS)),
                'build_state' => collect((array) ($design['build_states'] ?? []))->firstWhere('id', $state),
                'state_surfaces' => collect((array) ($pinned['state_records'] ?? []))->firstWhere('id', $state),
                'parts' => array_values(array_map(static fn (array $part): array => array_diff_key($part, ['points' => true]), array_filter($designParts, static fn (array $part): bool => in_array($part['id'], $named, true)))),
                'connections' => array_values(array_filter((array) ($design['connections'] ?? []), static fn (array $connection): bool => $touches((array) ($connection['between'] ?? [])))),
                'features' => array_values(array_filter((array) ($design['features'] ?? []), static fn (array $feature): bool => $touches((array) ($feature['parts'] ?? [])))),
                'systems' => array_values(array_filter((array) ($design['systems'] ?? []), static fn (array $system): bool => $touches((array) ($system['parts'] ?? [])))),
                'identity_rules' => array_values(array_filter((array) ($design['identity_rules'] ?? []), static fn (array $rule): bool => $touches((array) ($rule['refs'] ?? [])) || in_array('MOTORCYCLE', (array) ($rule['scope'] ?? []), true))),
                'limits' => $limits,
            ],
            'images' => $images,
            'composition_policy' => $policy,
        ];

        return [
            'payload' => $payload,
            'context' => [
                'format' => MotoContract::KEYFRAME_PROMPT_FORMAT,
                'shot_id' => (string) $shot['id'],
                'source_fingerprint' => $fingerprint,
                'part_ids' => array_values(array_map('strval', array_keys($designParts))),
                'work_ids' => $work,
                'image_count' => count($images),
                'max_prompt_chars' => $maxPromptChars,
            ],
        ];
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas()),
        );
    }

    public function check(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== MotoContract::KEYFRAME_PROMPT_FORMAT) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.MotoContract::KEYFRAME_PROMPT_FORMAT.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;

        if ($schema !== []) {
            return self::result(self::FAIL, $status, null, array_map(static fn (array $violation): string => trim($violation['path'].' '.$violation['message']), $schema));
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $issues = [];

        foreach (['project_id', 'prompt_version', 'shot_id', 'source_fingerprint'] as $key) {
            if (($response[$key] ?? null) !== ($context[$key] ?? null)) {
                $issues[] = "{$key} của phản hồi không khớp yêu cầu.";
            }
        }

        $refs = (array) $response['source_refs'];
        $unknownParts = array_values(array_diff((array) $refs['parts'], (array) $context['part_ids']));
        $unknownWork = array_values(array_diff((array) $refs['work'], (array) $context['work_ids']));
        $positions = (array) $refs['image_positions'];
        $count = (int) $context['image_count'];
        preg_match_all('/\bImage\s+(\d+)\b/i', (string) $response['image_prompt'], $mentions);
        $outside = array_values(array_unique(array_filter([...$positions, ...array_map('intval', $mentions[1])], static fn (int $position): bool => $position < 1 || $position > $count)));

        if ($unknownParts !== []) {
            $issues[] = 'source_refs.parts có ID không thuộc thiết kế: '.implode(', ', $unknownParts).'.';
        }

        if ($unknownWork !== []) {
            $issues[] = 'source_refs.work có ID không thuộc shot hoặc scene: '.implode(', ', $unknownWork).'.';
        }

        if (count($positions) !== count(array_unique($positions))) {
            $issues[] = 'source_refs.image_positions có vị trí lặp.';
        }

        if ($outside !== []) {
            $issues[] = 'Vị trí ảnh '.implode(', ', $outside).' nằm ngoài '.$count.' ảnh gửi kèm.';
        }

        if (mb_strlen((string) $response['image_prompt']) > (int) $context['max_prompt_chars']) {
            $issues[] = 'Prompt dài '.mb_strlen((string) $response['image_prompt']).' ký tự, vượt giới hạn '.$context['max_prompt_chars'].' của model ảnh.';
        }

        $warnings = [
            ...array_map(static fn (array $gap): string => 'Thiếu dữ kiện: '.$gap['missing'].' — '.$gap['why'], (array) $response['gaps']),
            ...array_map(static fn (array $conflict): string => 'Mâu thuẫn ('.implode(' / ', (array) $conflict['sources']).'): '.$conflict['what'], (array) $response['conflicts']),
        ];

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /** @return array{image_prompt: string, prompt_sha256: string} the prompt as the model wrote it, word for word, with its hash */
    public function derive(array $document, array $context): array
    {
        $prompt = (string) ($document['image_prompt'] ?? '');

        return ['image_prompt' => $prompt, 'prompt_sha256' => CanonicalJson::hashString($prompt)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string  $contract  ScriptEvaluation::contract() of the pinned bundle
     */
    public function contextProblem(array $context, string $contract): ?string
    {
        if (! collect(self::CONTRACTS)->contains(static fn (string $known): bool => str_starts_with($contract, $known.'#'))) {
            return "hợp đồng đầu ra là {$contract}; code kiểm Phase 9 cho ".implode(', ', self::CONTRACTS).'.';
        }

        return ($context['shot_id'] ?? '') === '' ? 'không có shot để viết prompt.' : null;
    }

    /**
     * @param  array<string, mixed>  $check  check()
     * @return bool whether a checked answer can be approved and sent: it passed every check and the model did not hold it back for a gap or a conflict
     */
    public static function usable(array $check): bool
    {
        return ($check['verdict'] ?? null) === self::PASS && ($check['status'] ?? null) === 'ok';
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, array<string, mixed>>  $configurations
     * @return list<string> every part the shot names: in and out of frame, in its work, held at its start and in its configurations
     */
    public static function partIds(array $shot, array $configurations): array
    {
        $ids = [
            ...array_column((array) ($shot['parts'] ?? []), 'part'),
            ...array_column((array) ($shot['off_frame'] ?? []), 'part'),
            ...array_keys((array) ($shot['held_parts'] ?? [])),
        ];

        foreach ((array) ($shot['work_items'] ?? []) as $item) {
            $ids = [...$ids, (string) $item['part'], ...array_column((array) $item['parts'], 'part')];
        }

        foreach ([...(array) ($shot['configurations']['start'] ?? []), ...(array) ($shot['configurations']['end'] ?? [])] as $id) {
            if (isset($configurations[$id]['part'])) {
                $ids[] = (string) $configurations[$id]['part'];
            }
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, array<string, mixed>>  $configurations
     * @return list<array<string, mixed>> the configuration records of these ids, whole
     */
    private static function configurations(array $ids, array $configurations): array
    {
        return array_values(array_filter(array_map(static fn (string $id): ?array => $configurations[$id] ?? null, $ids)));
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => self::CHECK_VERSION,
            'format' => MotoContract::KEYFRAME_PROMPT_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'judge' => 'not_run',
        ];
    }
}
