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
 * Skill tu mang hai diem chen o cuoi file; phan truoc `SOURCE MATERIAL:` la
 * chi dan (system), phan sau la du lieu (user). Cat dung mot lan tai day de
 * khong phai giu hai ban cua cung mot van ban.
 */
final class GeometryPromptAuthor
{
    private const SPLIT_MARKER = 'SOURCE MATERIAL:';

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $brief
     */
    public function author(
        array $brief,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $imageModel,
    ): GeometryPromptResult {
        $response = $this->client->complete(
            model: $this->model,
            system: $this->system($this->skill()),
            user: $this->user($brief),
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
     */
    private function user(array $brief): string
    {
        return self::SPLIT_MARKER."\n\n".json_encode(
            $brief,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
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
