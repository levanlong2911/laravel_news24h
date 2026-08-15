<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;

final class ClaudeConceptDesigner
{
    public const INSTRUCTION_VERSION = 'concept-v6';

    private const MAX_ATTEMPTS = 2;

    private const MAX_CORRECTION_CHARS = 1200;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly CreativeConceptParser $parser = new CreativeConceptParser,
        private readonly ConceptValidator $validator = new ConceptValidator,
        private readonly string $model = 'sonnet',
    ) {}

    public function design(InspirationBrief $brief, CategoryCreativeProfile $profile): ConceptDesignResult
    {
        $profile->assertConceptReady();
        $instruction = $this->instruction($profile);
        $input = json_encode([
            'article_focus' => $brief->articleFocus,
            'source_insights' => array_map(
                fn ($item) => ['aspect' => $item->aspect, 'summary' => $item->summary],
                $brief->sourceInsights,
            ),
            'uncovered_aspects' => $brief->uncoveredAspects($profile),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $violations = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $correction = $violations === [] ? '' : $this->correction($violations)."\n\n";
            $response = $this->llm->complete(new LlmRequest(
                $correction.$instruction,
                $input,
                self::INSTRUCTION_VERSION,
                $this->model,
                maxTokens: 2500,
                temperature: 0.7,
            ));

            try {
                $concept = $this->parser->parse($response->text)->canonicalised($profile);
                $result = $this->validator->validate($concept, $profile, $brief);

                // Retry CHI vi fatal. Rut gon van phong o luot sau thuong doi
                // cho dai sang truong khac chu khong lam concept dung hon.
                if (! $result->isFatal()) {
                    return new ConceptDesignResult($concept, $result->warnings, $attempt);
                }

                $violations = $result->fatalViolations;
            } catch (InvalidCreativeConcept $exception) {
                $violations = $exception->violations;
            }
        }

        throw new InvalidCreativeConcept($violations);
    }

    private function instruction(CategoryCreativeProfile $profile): string
    {
        $slots = [];
        foreach ($profile->identitySlots as $name => $spec) {
            // Ngan sach tu suy tu chinh khe: 120 ky tu -> 17 tu, 60 -> 8. Mot
            // con so chung se tu sinh warning o cac khe ngan.
            $guidance = isset($spec['guidance']) ? ' Guidance: '.$spec['guidance'] : '';
            $slots[] = $spec['type'] === 'text'
                ? '- '.$name.': one compact technical phrase, at most '.max(3, intdiv((int) $spec['max_length'], 7)).' words.'.$guidance
                : "- {$name}: {$spec['type']}, between {$spec['min']} and {$spec['max']}.{$guidance}";
        }

        $aspects = implode("\n", array_map(fn (string $aspect) => "- {$aspect}", $profile->inspectionAspects));
        $slotLines = implode("\n", $slots);
        $maxFeatures = ConceptValidator::MAX_FEATURES;

        $viewpoints = implode("\n", array_map(
            fn (string $name, string $text) => "- {$name}: {$text}",
            array_keys($profile->viewpointGuidance),
            $profile->viewpointGuidance,
        ));

        return <<<TEXT
        You are a concept designer. The material below comes from an existing product,
        but you are designing a NEW object that has never existed.

        {$profile->conceptMission}

        Never name a real company, designer, builder, brand, engine maker, owner, or
        existing product. Do not name the new design.

        Write in compact technical specification language. Do not use poetic,
        promotional, cinematic, or metaphorical prose. Use one clause whenever one
        clause is sufficient.

        Bad: "A poetic form that appears to flow endlessly through space, dissolving
        the boundary between structure and horizon."
        Good: "One continuous line integrates the primary volumes."

        The word budgets below are guidance, not a counting exercise. Staying near
        them keeps the specification readable.

        Return ONLY one raw JSON object with exactly these fields:

        "design_thesis": one sentence, at most 24 words, stating the organising idea
        of the whole design.

        "design_identity": an object with exactly these keys:
        {$slotLines}

        For every identity slot that counts repeated parts, preserve that exact
        count throughout the concept. If form_relationships groups those parts into
        fewer larger masses, state the grouping explicitly without changing the
        underlying part count. Do not introduce an unexplained competing count.

        "form_relationships": an object with exactly three fields, each at most
        30 words:
        - governing_line: the dominant line or geometry connecting the whole object
        - massing_rhythm: how major volumes transition, taper, overlap, or repeat
        - feature_integration: the PRINCIPLE by which signature features grow from
          the main form instead of looking attached afterward. State the principle
          only — never name an individual feature here.

        "signature_features": 1 to {$maxFeatures} objects. Each has
        "description" (one visible feature, at most 15 words) and "visible_from"
        (one or more of these viewpoints, each rendered from the camera described):
        {$viewpoints}

        List a viewpoint only when the feature is materially readable from that
        view without changing the camera or distorting the object. Natural
        self-occlusion is allowed. The description must not require mutually
        occluded portions, such as both sides of a three-dimensional object, to be
        visible simultaneously. Symmetry belongs in design_identity, not in a
        visibility claim.

        "decisions": exactly one object for every aspect below. Each object has
        "aspect", "provenance" (inspired|invented), and "decision" (at most 35 words).
        {$aspects}

        An inspired decision must transform the reference rather than reproduce its
        complete configuration. An uncovered aspect must use invented provenance.
        Do not write camera, lighting, rendering, provider, or prompt terminology.
        TEXT;
    }

    /** @param list<string> $violations */
    private function correction(array $violations): string
    {
        $text = 'CORRECTION REQUIRED. The previous response was rejected for: '.implode('; ', $violations);

        return mb_strlen($text) <= self::MAX_CORRECTION_CHARS
            ? $text
            : mb_substr($text, 0, self::MAX_CORRECTION_CHARS - 3).'...';
    }
}
