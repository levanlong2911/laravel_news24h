<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Moto\MotoContract;
use App\Video\Script\CheckedWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;

/**
 * The answer of the model that writes one package (SocialPublishingPlan), and the input it is written from.
 *
 * The check is of the contract alone: the answer is valid against its pinned schema, its ids are unique, the hook it
 * names is one of its hooks, every source it cites is one the input listed and every picture it proposes is one the
 * input offered. What the words say is for a person, and for the review a person may ask for.
 */
final class SocialPlan implements CheckedWorkflow
{
    public const FORMAT = 'social-publishing-plan-v1';

    public const INPUT = 'social-publishing-input-v1';

    public const CONTRACT = 'social/SocialPublishingPlan@1.0.0';

    public const CHECK_VERSION = 'social-plan-check-v1';

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    /** @var string what a model is told it reads, and what the screen says a model read: never more than was sent */
    public const BASIS = 'You are given the script the film was shot from, the timeline of its shots, its text cards and the design it shows. No picture and no sound of the film is given: you have not seen or heard the film.';

    /** @return array<string, mixed> the model and the limits of a run */
    public function settings(): array
    {
        return self::settingsOf('content');
    }

    /**
     * @param  list<string>  $parts  the prompt
     * @param  list<array<string, mixed>>  $schemas  the pinned output schemas
     */
    public function systemText(array $parts, array $schemas): string
    {
        return self::system($parts, $schemas, (string) config('video.social.content.schema_heading'));
    }

    /**
     * @param  array<string, mixed>  $source  the source a revision was written from: the film and its sources, the channel profile, the platform policy and the goal
     * @param  array{format: string, language: string}  $identity
     * @param  array<string, mixed>  $fields  the fields of the revision
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what the model reads, and what the check reads back. No id of an account, no path, no hash of a file and no token is in the payload
     */
    public static function inputs(array $source, array $identity, array $fields): array
    {
        $rules = (array) $source['policy']['rules'];
        $channel = (array) $source['channel'];
        $media = (array) $source['film']['media'];

        return [
            'payload' => [
                'input_format' => self::INPUT,
                'package' => [
                    'format' => $identity['format'],
                    'format_label' => (string) $rules['label'],
                    'platform' => (string) $rules['platform'],
                    'language' => $identity['language'],
                    'language_name' => (string) (config('video.social.languages')[$identity['language']] ?? $identity['language']),
                    'goal' => $source['goal'] ?? null,
                ],
                'channel' => [
                    'display_name' => $channel['display_name'] ?? null,
                    'audience' => $channel['audience'] ?? null,
                    'brand_voice' => $channel['brand_voice'] ?? null,
                    'default_goal' => $channel['default_goal'] ?? null,
                    'default_cta' => $channel['default_cta'] ?? null,
                    'link_added_by_code' => is_string($fields['link'] ?? null),
                    'disclosure_sentence_added_by_code' => $fields['disclosure_text'] ?? null,
                ],
                'limits' => SocialPolicy::limits($rules, SocialPackageDocument::reserved($fields)),
                'film' => [
                    'ref' => 'film',
                    'duration_ms' => $media['duration_ms'] ?? null,
                    'width' => $media['width'] ?? null,
                    'height' => $media['height'] ?? null,
                    'has_audio' => $media['has_audio'] ?? null,
                    'shots' => $source['film']['shots'] ?? null,
                    'basis' => self::BASIS,
                ],
                'script' => ['ref' => 'script'] + (array) $source['facts']['script'],
                'timeline' => (array) $source['facts']['timeline'],
                'cards' => (array) $source['facts']['cards'],
                'design' => $source['facts']['design'] === null ? null : ['ref' => 'design'] + (array) $source['facts']['design'],
                'assets' => array_map(static fn (array $asset): array => array_intersect_key($asset, array_flip(['id', 'kind', 'at_ms', 'shot_id', 'scene_id', 'what'])), (array) $source['assets']),
                'refs' => (array) $source['refs'],
                'missing' => (array) $source['missing'],
            ],
            'context' => [
                'format' => self::FORMAT,
                'refs' => array_values(array_column((array) $source['refs'], 'ref')),
                'asset_ids' => array_values(array_column((array) $source['assets'], 'id')),
                'title_used' => (bool) $rules['text']['title']['used'],
                'keywords_used' => (bool) $rules['text']['keywords']['used'],
            ],
        ];
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return self::violations($decoded, $bundle);
    }

    public function check(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return self::result(self::FORMAT, self::CHECK_VERSION, self::UNSCORED, 'Phản hồi không đọc được JSON.');
        }

        if ($schema !== []) {
            return self::result(self::FORMAT, self::CHECK_VERSION, self::FAIL, null, self::lines($schema));
        }

        $issues = [];
        $hooks = array_column((array) $response['hook_options'], 'id');

        foreach ([['hook_options', $hooks], ['claims', array_column((array) $response['claims'], 'id')], ['artwork_options', array_column((array) $response['artwork_options'], 'id')]] as [$list, $ids]) {
            if (count($ids) !== count(array_unique($ids))) {
                $issues[] = "{$list} có id lặp.";
            }
        }

        if (! in_array($response['selected_hook_id'], $hooks, true)) {
            $issues[] = 'selected_hook_id '.json_encode($response['selected_hook_id']).' không phải id của hook nào trong hook_options.';
        }

        foreach ((array) $response['claims'] as $claim) {
            $unknown = array_values(array_diff((array) $claim['source_refs'], (array) $context['refs']));

            if ($unknown !== []) {
                $issues[] = "Claim {$claim['id']} dẫn nguồn không có trong danh sách refs của đầu vào: ".implode(', ', $unknown).'.';
            }
        }

        foreach ((array) $response['artwork_options'] as $option) {
            if (! in_array($option['asset_id'], (array) $context['asset_ids'], true)) {
                $issues[] = "Đề xuất ảnh {$option['id']} dùng asset_id ".json_encode($option['asset_id']).' không có trong danh sách assets của đầu vào.';
            }
        }

        $warnings = [];

        if (! $context['title_used'] && $response['copy']['title'] !== null) {
            $warnings[] = 'Định dạng này không dùng tiêu đề nhưng AI vẫn viết — xóa tiêu đề trước khi duyệt.';
        }

        if (! $context['keywords_used'] && $response['copy']['keywords'] !== []) {
            $warnings[] = 'Định dạng này không dùng từ khóa nhưng AI vẫn viết — xóa từ khóa trước khi duyệt.';
        }

        return self::result(self::FORMAT, self::CHECK_VERSION, $issues === [] ? self::PASS : self::FAIL, null, [], $issues, $warnings);
    }

    /** @return array{hooks: int, claims: int, artwork_options: int, review_notes: int} */
    public function derive(array $document, array $context): array
    {
        return [
            'hooks' => count((array) $document['hook_options']),
            'claims' => count((array) $document['claims']),
            'artwork_options' => count((array) $document['artwork_options']),
            'review_notes' => count((array) $document['review_notes']),
        ];
    }

    /** @return array<string, mixed> the model and the limits config('video.social') gives a kind of run */
    public static function settingsOf(string $kind): array
    {
        $config = (array) config('video.social.'.$kind);

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
        ];
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $schemas
     */
    public static function system(array $parts, array $schemas, string $heading): string
    {
        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * The answer against the output schema its run pinned, as it stands: an answer of Phase 12 has no envelope around it.
     *
     * @return list<array{code: string, path: string, message: string}> every missing field, field the schema does not name and wrong type, by its path
     */
    public static function violations(object $decoded, ResolvedPhase $bundle): array
    {
        return array_map(
            static fn (array $fault): array => ['code' => (string) $fault['code'], 'path' => (string) $fault['path'], 'message' => (string) $fault['message']],
            PhaseOneEvidence::schemaDiagnostics($decoded, $bundle->outputSchemas()),
        );
    }

    /**
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return list<string>
     */
    public static function lines(array $schema): array
    {
        return array_map(static fn (array $violation): string => trim(($violation['path'] === '' ? '(gốc)' : $violation['path']).': '.$violation['message']), $schema);
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function result(string $format, string $version, string $verdict, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => $version,
            'format' => $format,
            'verdict' => $verdict,
            'status' => $verdict === self::PASS ? 'ok' : null,
            'error' => $error,
            'schema' => $schema,
            'issues' => $issues,
            'warnings' => $warnings,
            'judge' => 'not_run',
        ];
    }
}
