<?php

declare(strict_types=1);

namespace App\Video\Social;

use App\Video\Script\CheckedWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;

/**
 * The answer of the model that reviews one package revision (SocialPublishingReview), and the input it reads.
 * A review changes nothing and approves nothing; the check is of its contract alone, as for a plan.
 */
final class SocialReview implements CheckedWorkflow
{
    public const FORMAT = 'social-publishing-review-v1';

    public const INPUT = 'social-publishing-review-input-v1';

    public const CONTRACT = 'social/SocialPublishingReview@1.0.0';

    public const CHECK_VERSION = 'social-review-check-v1';

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return SocialPlan::settingsOf('review');
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $schemas
     */
    public function systemText(array $parts, array $schemas): string
    {
        return SocialPlan::system($parts, $schemas, (string) config('video.social.review.schema_heading'));
    }

    /**
     * @param  array<string, mixed>  $source  the source the revision was written from
     * @param  array{format: string, language: string}  $identity
     * @param  array<string, mixed>  $document  the package document of the revision
     * @param  ?string  $coverText  the words on the cover of the revision, null for none
     * @return array{payload: array<string, mixed>, context: array<string, mixed>}
     */
    public static function inputs(array $source, array $identity, array $document, ?string $coverText): array
    {
        $writer = SocialPlan::inputs($source, $identity, SocialPackageDocument::fields($document));
        $payload = array_diff_key($writer['payload'], ['assets' => true]);

        return [
            'payload' => array_replace($payload, [
                'input_format' => self::INPUT,
                'package' => $payload['package'] + [
                    'document' => (array) $document['content'] + [
                        'synthetic_media' => (bool) $document['disclosure']['synthetic_media'],
                        'cover_text' => $coverText,
                        'has_cover' => $document['artwork'] !== null,
                        'rendered' => $document['rendered'],
                    ],
                ],
            ]),
            'context' => ['format' => self::FORMAT, 'refs' => $writer['context']['refs']],
        ];
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return SocialPlan::violations($decoded, $bundle);
    }

    public function check(?array $response, array $context, array $schema): array
    {
        if ($response === null) {
            return SocialPlan::result(self::FORMAT, self::CHECK_VERSION, SocialPlan::UNSCORED, 'Phản hồi không đọc được JSON.');
        }

        if ($schema !== []) {
            return SocialPlan::result(self::FORMAT, self::CHECK_VERSION, SocialPlan::FAIL, null, SocialPlan::lines($schema));
        }

        $issues = [];
        $ids = array_column((array) $response['findings'], 'id');

        if (count($ids) !== count(array_unique($ids))) {
            $issues[] = 'findings có id lặp.';
        }

        foreach ((array) $response['findings'] as $finding) {
            $unknown = array_values(array_diff((array) $finding['source_refs'], (array) $context['refs']));

            if ($unknown !== []) {
                $issues[] = "Phát hiện {$finding['id']} dẫn nguồn không có trong danh sách refs của đầu vào: ".implode(', ', $unknown).'.';
            }
        }

        return SocialPlan::result(self::FORMAT, self::CHECK_VERSION, $issues === [] ? SocialPlan::PASS : SocialPlan::FAIL, null, [], $issues);
    }

    /** @return array{findings: int, major: int} */
    public function derive(array $document, array $context): array
    {
        $findings = (array) $document['findings'];

        return ['findings' => count($findings), 'major' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'major'))];
    }
}
