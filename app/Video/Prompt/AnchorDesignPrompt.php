<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Services\Video\OpenAiImageClient;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Reference\ReferenceView;
use App\Video\Screenplay\DesignText;
use RuntimeException;

final class AnchorDesignPrompt
{
    public const AUTHOR = 'code';

    /** @var list<string> */
    private const GEOMETRY_ORDER = [
        'hull_geometry', 'bow_geometry', 'stern_geometry', 'decks', 'superstructure_geometry', 'governing_lines',
        'primary_masses', 'primary_voids', 'openings', 'surfaces', 'permanent_secondary_geometry', 'transitions',
        'geometric_relationships',
    ];

    public function __construct(
        private readonly string $templatePath,
        public readonly string $version,
    ) {}

    public function skillHash(): string
    {
        return hash('sha256', $this->version."\n".$this->template());
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $design
     */
    public function compile(array $source, array $design, ImageSize $size): string
    {
        $names = DesignText::names($design);
        $seen = [];
        $subject = $source['subject'];
        $nearEnd = (string) $source['near_end'];
        $side = (string) $source['side'];
        $farEnd = $nearEnd === 'bow' ? 'stern' : 'bow';

        $subjectLines = [];

        foreach (['proportion_system', 'global_silhouette'] as $key) {
            $subjectLines = [...$subjectLines, ...DesignText::section($key, $subject[$key], $names, $seen)];
        }

        $geometryLines = [];

        foreach (self::GEOMETRY_ORDER as $key) {
            if (! array_key_exists($key, $source['geometry'])) {
                continue;
            }

            $geometryLines = [...$geometryLines, ...DesignText::section($key, self::shaped($key, $source['geometry'][$key], $names), $names, $seen)];
        }

        $identityLines = [];

        foreach (['signature_regions', 'must_preserve', 'must_not_introduce'] as $key) {
            $identityLines = [...$identityLines, ...DesignText::section($key, self::shaped($key, $source['identity'][$key], $names), $names, $seen)];
        }

        $text = static fn (array $lines): string => DesignText::readable(implode("\n", $lines), $names);

        return trim(strtr($this->template(), [
            '{name}' => (string) $subject['name'],
            '{length_m}' => (string) $subject['length_m'],
            '{beam_m}' => (string) $subject['beam_m'],
            '{length_beam_ratio}' => (string) $subject['length_beam_ratio'],
            '{subject}' => $text($subjectLines),
            '{visible_geometry}' => $text($geometryLines),
            '{identity_constraints}' => $text($identityLines),
            '{near_end}' => $nearEnd,
            '{far_end}' => $farEnd,
            '{side}' => $side,
            '{frame_direction}' => ReferenceView::frameDirection($side),
            '{hidden_end}' => ReferenceView::hiddenEnd($nearEnd, $side),
            '{aspect_ratio}' => $size->aspectRatio(),
            '{orientation}' => $size->orientation(),
        ]));
    }

    public function fits(string $prompt): bool
    {
        return mb_strlen($prompt) <= OpenAiImageClient::MAX_PROMPT_CHARS;
    }

    public function compiled(string $prompt, AnchorStage $stage, ImageSize $size, ImageModel $model): CompiledAnchorPrompt
    {
        return new CompiledAnchorPrompt(
            prompt: $prompt,
            negativePrompt: null,
            nativeControls: ['size' => $size->value, 'width' => $size->width(), 'height' => $size->height()],
            promptVersion: $this->version,
            provider: $model->provider(),
            model: $model->value,
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
            environmentPolicyVersion: $this->version,
            subjectState: $stage->value,
            subjectStateVersion: self::AUTHOR,
        );
    }

    /**
     * @param  array<string, string>  $names
     */
    private static function shaped(string $key, mixed $node, array $names): mixed
    {
        if (! is_array($node) || ! array_is_list($node)) {
            return $node;
        }

        return match ($key) {
            'primary_masses', 'primary_voids' => array_map(
                static fn (array $row): string => ucfirst(DesignText::name($row['id'] ?? null, $names)).': '.DesignText::value($row, $names),
                $node,
            ),
            'transitions' => array_map(
                static fn (array $row): string => 'Between '.implode(' and ', array_unique(array_map(static fn (mixed $id): string => DesignText::name($id, $names), (array) ($row['between'] ?? []))))
                    .': '.trim((string) ($row['description'] ?? '')),
                $node,
            ),
            'signature_regions' => array_map(
                static fn (array $row): string => trim((string) ($row['feature'] ?? '')).': '.trim((string) ($row['description'] ?? ''))
                    .(($row['topology'] ?? []) === [] ? '' : ' Seen from this view, '.DesignText::value($row['topology'], $names).'.'),
                $node,
            ),
            default => $node,
        };
    }

    private function template(): string
    {
        if (! is_file($this->templatePath)) {
            throw new RuntimeException('Anchor prompt template not found: '.$this->templatePath);
        }

        return (string) file_get_contents($this->templatePath);
    }
}
