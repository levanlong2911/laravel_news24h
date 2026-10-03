<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Prompt\Exceptions\TextCompletionException;

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
        $response = $this->client->complete(
            model: $this->model,
            system: $this->system($this->skill()),
            user: $this->user($brief, $downstream),
            maxTokens: $this->maxTokens,
        );

        if ($response->wasTruncated()) {
            throw new TextCompletionException(
                'Image prompt was cut off at the token limit; raise the provider max_tokens.'
            );
        }

        return new GeometryPromptResult(
            compiled: $this->compiled(
                $response->text, $response->model, $stage, $size, $imageModel
            ),
            authorModel: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
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
