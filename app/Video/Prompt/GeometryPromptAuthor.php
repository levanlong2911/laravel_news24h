<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Reference\ReferenceView;
use App\Video\Screenplay\VesselDesign;

/**
 * Sinh prompt anh bang mot cu goi model, khong qua Python.
 *
 * Phan skill truoc `SOURCE MATERIAL:` la chi dan (system). Tin nhan user gom
 * `SOURCE MATERIAL:` kem JSON nguon, va `OPTIONAL DOWNSTREAM REQUIREMENTS:`
 * kem JSON yeu cau dau ra khi nguoi goi truyen vao.
 */
final class GeometryPromptAuthor
{
    private const SPLIT_MARKER = 'SOURCE MATERIAL:';

    private const DOWNSTREAM_MARKER = 'OPTIONAL DOWNSTREAM REQUIREMENTS:';

    private const PRESENTATION_MARKER = 'PRESENTATION:';

    public const PRESENTATION_KEY = 'presentation';

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    /**
     * @return array{aspect_ratio: string, orientation: string}
     */
    public static function downstreamFor(ImageSize $size): array
    {
        return [
            'aspect_ratio' => $size->aspectRatio(),
            'orientation' => $size->orientation(),
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>|null  $downstream
     */
    public function author(
        array $brief,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $imageModel,
        ?array $downstream = null,
    ): GeometryPromptResult {
        $edited = isset($brief[VesselDesign::EXTERIOR_DESIGN_KEY]);
        $presentation = $edited ? $this->presentation($brief, $downstream) : null;

        if ($presentation !== null) {
            $brief[self::PRESENTATION_KEY] = $presentation;
        }

        $audited = ! $edited && isset($brief['design']['canonical_design']);
        if ($audited) {
            $brief['coverage_requirements'] = array_map(
                static fn (string $path, array $row): array => ['source_path' => $path] + $row,
                array_keys($requirements = AnchorCoverage::requirements($brief)),
                $requirements,
            );
        }
        $response = $this->client->complete(
            model: $this->model,
            system: $this->system($this->skill()).($audited ? "\n\nFor this canonical design request, override plain-text output: return only a JSON object with prompt, coverage, conflicts and alignment as the CANONICAL COVERAGE REQUIREMENT specifies. Every coverage_requirements entry is checked exactly as it is written: its quote must appear verbatim in prompt, be at least quote_min_length characters, and contain at least one listed word from every quote_must_state group. No markdown fences." : ''),
            user: $this->user($brief, $downstream),
            maxTokens: $this->maxTokens,
            outputSchema: $this->client instanceof AnthropicTextClient ? null : ($edited ? self::editedSchema() : ($audited ? AnchorCoverage::schema() : null)),
        );

        if ($response->wasTruncated()) {
            throw TextCompletionException::afterResponse(
                'Image prompt was cut off at the token limit; raise the provider max_tokens.',
                $response->usage + ['prompt_tokens' => $response->inputTokens, 'completion_tokens' => $response->outputTokens],
                $response->text,
                $response->model,
            );
        }

        $audit = [];
        $prompt = $response->text;
        if ($edited) {
            $decoded = json_decode($response->text, true);
            $geometry = is_array($decoded) && is_string($decoded['geometry_prompt'] ?? null) ? trim($decoded['geometry_prompt']) : '';
            $conflicts = is_array($decoded) && is_array($decoded['conflicts'] ?? null) ? $decoded['conflicts'] : null;
            $prompt = $geometry === '' ? '' : $geometry."\n\n".$presentation;
            $audit = [
                'source_version' => VesselDesign::ANCHOR_SOURCE_VERSION,
                'geometry_prompt' => $geometry,
                'conflicts' => $conflicts,
                self::PRESENTATION_KEY => $presentation,
            ];
        } elseif ($audited) {
            $decoded = json_decode($response->text, true);
            $prompt = is_array($decoded) && is_string($decoded['prompt'] ?? null) ? $decoded['prompt'] : '';
            $audit = [
                'coverage_version' => AnchorCoverage::VERSION,
                'coverage' => is_array($decoded) ? ($decoded['coverage'] ?? null) : null,
                'conflicts' => is_array($decoded) ? ($decoded['conflicts'] ?? null) : null,
                'alignment' => is_array($decoded) ? ($decoded['alignment'] ?? null) : null,
            ];
        }
        return new GeometryPromptResult(
            compiled: $this->compiled(
                $prompt, $response->model, $stage, $size, $imageModel
            ),
            authorModel: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            rawResponse: $response->text,
            audit: $audit,
            usage: $response->usage,
        );
    }

    /**
     * Dung lai prompt da luu trong so `video_planning_stages`, khong goi model.
     *
     * @param  array<string, mixed>  $stored
     */
    public function rehydrate(
        array $stored,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $imageModel,
    ): ?CompiledAnchorPrompt {
        $prompt = (string) ($stored['prompt'] ?? '');

        if (trim($prompt) === '') {
            return null;
        }

        return $this->compiled(
            $prompt,
            (string) ($stored['author_model'] ?? $this->model),
            $stage,
            $size,
            $imageModel,
        );
    }

    /**
     * Doi skill la doi hash, nen ban prompt cu khong con duoc dung lai.
     */
    public function skillHash(): string
    {
        return hash('sha256', $this->skill());
    }

    /** @return array<string, mixed> */
    public static function editedSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['geometry_prompt', 'conflicts'],
            'properties' => [
                'geometry_prompt' => ['type' => 'string'],
                'conflicts' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>|null  $downstream
     */
    private function presentation(array $brief, ?array $downstream): string
    {
        $skill = $this->skill();
        $position = mb_strpos($skill, self::PRESENTATION_MARKER);

        if ($position === false) {
            throw new TextCompletionException('Image prompt skill has no "'.self::PRESENTATION_MARKER.'" template.');
        }

        $view = (string) ($brief['requested_anchor_view']['view'] ?? '');

        if (! preg_match('/^(bow|stern)_three_quarter_(port|starboard)$/', $view, $parts)) {
            throw new TextCompletionException('A design anchor prompt needs a chosen three-quarter view.');
        }

        return strtr(trim(mb_substr($skill, $position + mb_strlen(self::PRESENTATION_MARKER))), [
            '{near_end}' => $parts[1],
            '{far_end}' => $parts[1] === 'bow' ? 'stern' : 'bow',
            '{side}' => $parts[2],
            '{frame_direction}' => ReferenceView::frameDirection($parts[2]),
            '{hidden_end}' => ReferenceView::hiddenEnd($parts[1], $parts[2]),
            '{aspect_ratio}' => (string) ($downstream['aspect_ratio'] ?? ''),
            '{orientation}' => (string) ($downstream['orientation'] ?? ''),
        ]);
    }

    private function skill(): string
    {
        if (! is_file($this->promptPath)) {
            throw new TextCompletionException(
                'Image prompt skill not found: '.$this->promptPath
            );
        }

        return (string) file_get_contents($this->promptPath);
    }

    private function system(string $skill): string
    {
        $position = mb_strpos($skill, self::SPLIT_MARKER);

        if ($position === false) {
            throw new TextCompletionException(
                'Image prompt skill has no "'.self::SPLIT_MARKER.'" insertion point.'
            );
        }

        return trim(mb_substr($skill, 0, $position));
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>|null  $downstream
     */
    private function user(array $brief, ?array $downstream): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;
        $user = self::SPLIT_MARKER."\n\n".json_encode($brief, $flags);

        return $downstream === null
            ? $user
            : $user."\n\n".self::DOWNSTREAM_MARKER."\n\n".json_encode($downstream, $flags);
    }

    /**
     * Duong nay KHONG co canonical revision, nen moi hash canonical de rong.
     * `prompt_version` la thu phan biet prompt do bo nao sinh ra.
     */
    private function compiled(
        string $prompt,
        string $authorModel,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $imageModel,
    ): CompiledAnchorPrompt {
        return new CompiledAnchorPrompt(
            prompt: $prompt,
            negativePrompt: null,
            nativeControls: [
                'size' => $size->value,
                'width' => $size->width(),
                'height' => $size->height(),
            ],
            promptVersion: $this->promptVersion,
            provider: $imageModel->provider(),
            model: $imageModel->value,
            canonicalHash: '',
            projectionHash: '',
            constraintSetHash: '',
            coalescedConstraintHash: '',
            promptSpecHash: '',
            providerPromptPlanHash: '',
            promptHash: hash('sha256', $prompt),
            negativePromptHash: null,
            promptManifestPath: null,
            sourceRevisionId: '',
            assetRole: 'identity_anchor',
            environmentPolicy: 'neutral_studio',
            environmentPolicyVersion: $this->promptVersion,
            subjectState: $stage->value,
            subjectStateVersion: $authorModel,
        );
    }
}
