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
    private const FORM_ORDER = ['primary_masses', 'superstructure_geometry', 'decks', 'governing_lines'];

    /** @var list<string> */
    private const EXTERIOR_ORDER = [
        'hull_geometry', 'bow_geometry', 'stern_geometry', 'primary_voids', 'openings', 'surfaces',
        'permanent_secondary_geometry', 'configuration_states', 'transitions', 'geometric_relationships',
    ];

    private const CONSTRUCTION_RULE = 'This passage from the design states only how the structure is built and from which materials; '
        .'it does not ask for a building site, construction stages or a cutaway:';

    private const MANDATORY_PRIORITY = 'P0';

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

        $geometry = $source['geometry'];
        $identity = $source['identity'];
        $lines = static function (array $keys, array $from) use ($names, &$seen): array {
            $out = [];

            foreach ($keys as $key) {
                if (array_key_exists($key, $from)) {
                    $out = [...$out, ...DesignText::section($key, self::shaped($key, $from[$key], $names), $names, $seen)];
                }
            }

            return $out;
        };

        $mandatory = $lines(['must_preserve'], ['must_preserve' => self::mandatoryFirst((array) ($identity['must_preserve'] ?? []))]);
        $form = [
            ...$lines(['global_silhouette', 'proportion_system'], $subject),
            ...$lines(self::FORM_ORDER, $geometry),
        ];
        $exterior = $lines(self::EXTERIOR_ORDER, $geometry);
        $signature = $lines(['signature_regions'], $identity);
        $forbidden = $lines(['must_not_introduce'], $identity);

        $text = static fn (array $lines): string => DesignText::readable(implode("\n", $lines), $names);
        $block = static fn (string $title, string $body): string => $body === '' ? '' : $title."\n".$body;
        $construction = is_string($source['construction'] ?? null) && trim($source['construction']) !== '' ? trim($source['construction']) : '';

        $designSections = implode("\n\n", array_filter([
            $block('MANDATORY GEOMETRY', $text($mandatory)),
            $block('OVERALL FORM', $text($form)),
            $block('EXTERIOR GEOMETRY', $text($exterior)),
            $block('SIGNATURE FEATURES', $text($signature)),
            $block('CONSTRUCTION SOURCE', $construction === '' ? '' : self::CONSTRUCTION_RULE."\n".$construction),
        ], static fn (string $part): bool => $part !== ''));

        return trim(strtr($this->template(), [
            '{name}' => (string) $subject['name'],
            '{length_m}' => (string) $subject['length_m'],
            '{beam_m}' => (string) $subject['beam_m'],
            '{length_beam_ratio}' => (string) $subject['length_beam_ratio'],
            '{design_sections}' => $designSections,
            '{forbidden_section}' => $block('FORBIDDEN INTERPRETATIONS', $text($forbidden)),
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
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    private static function mandatoryFirst(array $rows): array
    {
        $first = static fn (mixed $row): bool => is_array($row) && ($row['priority'] ?? null) === self::MANDATORY_PRIORITY;

        return [...array_values(array_filter($rows, $first)), ...array_values(array_filter($rows, static fn (mixed $row): bool => ! $first($row)))];
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
