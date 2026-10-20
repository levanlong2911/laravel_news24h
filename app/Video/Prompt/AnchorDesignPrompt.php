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
use App\Video\Screenplay\VesselDesign;
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

    /** @var list<string> */
    private const UNIT_SKIPPED_KEYS = ['schema_version', 'author_model', 'status'];

    /** @var array<string, string> */
    private const POLICY_BLOCKS = ['state' => 'UNFINISHED STATE', 'camera' => 'CAMERA AND FRAMING'];

    public function __construct(
        private readonly string $templatePath,
        public readonly string $version,
        private readonly ?string $v3TemplatePath = null,
        private readonly ?string $v3Version = null,
        private readonly ?string $v4TemplatePath = null,
        private readonly ?string $v4Version = null,
        private readonly ?string $geometryTemplatePath = null,
        private readonly ?string $geometryVersion = null,
    ) {}

    /** @param array<string, mixed> $design */
    public function forDesign(array $design): self
    {
        if (VesselDesign::isV4($design) && $this->v4TemplatePath !== null && $this->v4Version !== null) {
            return new self($this->v4TemplatePath, $this->v4Version, $this->v3TemplatePath, $this->v3Version, $this->v4TemplatePath, $this->v4Version,
                $this->geometryTemplatePath, $this->geometryVersion);
        }

        return VesselDesign::isV3($design) && $this->v3TemplatePath !== null && $this->v3Version !== null
            ? new self($this->v3TemplatePath, $this->v3Version, $this->v3TemplatePath, $this->v3Version, $this->v4TemplatePath, $this->v4Version,
                $this->geometryTemplatePath, $this->geometryVersion)
            : $this;
    }

    /** @return list<string> */
    public function versions(): array
    {
        return array_values(array_filter([$this->version, $this->v3Version, $this->v4Version]));
    }

    public function geometryVersion(): ?string
    {
        return $this->geometryVersion;
    }

    public function geometryTemplateHash(): string
    {
        return hash('sha256', $this->geometryVersion."\n".$this->geometryTemplate());
    }

    /**
     * @param  array<string, mixed>  $design
     * @return string the anchor prompt: the fixed frame around the geometry prose, which is inserted word for word
     */
    public function compileProse(array $design, string $prose, ImageSize $size): string
    {
        $frame = ReferenceView::DESIGN_ANCHOR_FRAME;
        $farEnd = $frame['near_end'] === 'bow' ? 'stern' : 'bow';

        return trim(strtr($this->geometryTemplate(), [
            '{name}' => (string) ($design[VesselDesign::VESSEL_KEY]['name'] ?? ''),
            '{length_m}' => (string) ($design['principal_dimensions']['length_m'] ?? ''),
            '{beam_m}' => (string) ($design['principal_dimensions']['beam_m'] ?? ''),
            '{length_beam_ratio}' => (string) VesselDesign::lengthBeamRatio($design),
            '{geometry_prose}' => trim($prose),
            '{near_end}' => $frame['near_end'],
            '{far_end}' => $farEnd,
            '{side}' => $frame['side'],
            '{frame_direction}' => ReferenceView::frameDirection($frame['side']),
            '{hidden_end}' => ReferenceView::hiddenEnd($frame['near_end'], $frame['side']),
            '{aspect_ratio}' => $size->aspectRatio(),
            '{orientation}' => $size->orientation(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $design
     * @return array{state: string, camera: string} the fixed state policy and camera of the anchor, as the prompt states them
     */
    public function policy(array $design, ImageSize $size): array
    {
        $blocks = [];

        foreach (explode("\n\n", $this->compileProse($design, '', $size)) as $block) {
            foreach (self::POLICY_BLOCKS as $key => $title) {
                if (str_starts_with(trim($block), $title)) {
                    $blocks[$key] = trim($block);
                }
            }
        }

        return ['state' => $blocks['state'] ?? '', 'camera' => $blocks['camera'] ?? ''];
    }

    /**
     * @param  array<string, mixed>  $design
     * @return list<string> every unit of the design the geometry writer must account for: each list row by its id, each other field by its path
     */
    public static function sourceUnits(array $design): array
    {
        $units = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$units): void {
            if (! is_array($node) || $node === [] || (array_is_list($node) && array_filter($node, 'is_array') === [])) {
                $units[] = $path;

                return;
            }

            if (array_is_list($node)) {
                foreach ($node as $index => $row) {
                    $id = is_array($row) ? ($row['id'] ?? $row['feature_id'] ?? $row['component_id'] ?? null) : null;
                    $units[] = $path.'.'.(is_string($id) && $id !== '' ? $id : (string) $index);
                }

                return;
            }

            foreach ($node as $key => $child) {
                if (! in_array($key, self::UNIT_SKIPPED_KEYS, true)) {
                    $walk($child, $path === '' ? (string) $key : $path.'.'.$key);
                }
            }
        };
        $walk($design, '');

        return array_values(array_unique($units));
    }

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
        if (VesselDesign::isV3($design)) {
            return $this->compileLabelled($source, $design, $size);
        }

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

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $design
     */
    private function compileLabelled(array $source, array $design, ImageSize $size): string
    {
        $labels = VesselDesign::v3Labels($design);
        $descriptions = VesselDesign::descriptions($design);
        $missing = [];
        $subject = $source['subject'];
        $geometry = $source['geometry'];
        $identity = $source['identity'];
        $nearEnd = (string) $source['near_end'];
        $side = (string) $source['side'];
        $farEnd = $nearEnd === 'bow' ? 'stern' : 'bow';
        $lines = static function (array $keys, array $from) use ($labels, $descriptions, &$missing): array {
            $out = [];

            foreach ($keys as $key) {
                if (array_key_exists($key, $from)) {
                    $out = [...$out, ...($descriptions === []
                        ? DesignText::labelled($key, $from[$key], $labels, $missing)
                        : DesignText::described($key, $from[$key], $descriptions[$key] ?? [], $labels, $missing))];
                }
            }

            return $out;
        };
        $block = static fn (string $title, array $body): string => $body === [] ? '' : $title."\n".implode("\n", $body);
        $construction = array_values(array_map(
            static fn (string $field, string $text): string => '- '.$field.': '.$text,
            array_keys((array) ($source['construction'] ?? [])),
            array_values((array) ($source['construction'] ?? [])),
        ));

        $designSections = implode("\n\n", array_filter([
            $block('MANDATORY GEOMETRY', $lines(['must_preserve'], ['must_preserve' => self::mandatoryFirst((array) ($identity['must_preserve'] ?? []))])),
            $block('OVERALL FORM', [...$lines(['global_silhouette', 'proportion_system'], $subject), ...$lines(self::FORM_ORDER, $geometry)]),
            $block('EXTERIOR GEOMETRY', $lines(self::EXTERIOR_ORDER, $geometry)),
            $block('SIGNATURE FEATURES', $lines(['signature_regions'], $identity)),
            $construction === [] ? '' : 'CONSTRUCTION SOURCE'."\n".self::CONSTRUCTION_RULE."\n".implode("\n", $construction),
        ], static fn (string $part): bool => $part !== ''));
        $forbidden = $block('FORBIDDEN INTERPRETATIONS', $lines(['must_not_introduce'], $identity));

        if ($missing !== []) {
            throw new RuntimeException('anchor_design_name_missing: '.implode(', ', array_unique($missing)));
        }

        return trim(strtr($this->template(), [
            '{name}' => (string) $subject['name'],
            '{length_m}' => (string) $subject['length_m'],
            '{beam_m}' => (string) $subject['beam_m'],
            '{length_beam_ratio}' => (string) $subject['length_beam_ratio'],
            '{design_sections}' => $designSections,
            '{forbidden_section}' => $forbidden,
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

    public function compiled(string $prompt, AnchorStage $stage, ImageSize $size, ImageModel $model, ?string $author = null): CompiledAnchorPrompt
    {
        $version = $author === null ? $this->version : (string) $this->geometryVersion;

        return new CompiledAnchorPrompt(
            prompt: $prompt,
            negativePrompt: null,
            nativeControls: ['size' => $size->value, 'width' => $size->width(), 'height' => $size->height()],
            promptVersion: $version,
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
            environmentPolicyVersion: $version,
            subjectState: $stage->value,
            subjectStateVersion: $author ?? self::AUTHOR,
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

    private function geometryTemplate(): string
    {
        if ($this->geometryTemplatePath === null || ! is_file($this->geometryTemplatePath)) {
            throw new RuntimeException('Anchor geometry template not found: '.$this->geometryTemplatePath);
        }

        return (string) file_get_contents($this->geometryTemplatePath);
    }

    private function template(): string
    {
        if (! is_file($this->templatePath)) {
            throw new RuntimeException('Anchor prompt template not found: '.$this->templatePath);
        }

        return (string) file_get_contents($this->templatePath);
    }
}
