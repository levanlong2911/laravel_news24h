<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;

final class ClaudeConceptDesigner
{
    public const INSTRUCTION_VERSION = 'concept-v18';

    public const MODEL = 'sonnet5';

    private const MAX_ATTEMPTS = 1;

    // [DEAD 2026-08-19] MAX_ATTEMPTS = 1 nen khong con duong toi — xoa sau khi xong du an.
    // private const MAX_CORRECTION_CHARS = 1200;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly CreativeConceptParser $parser = new CreativeConceptParser,
        private readonly ConceptValidator $validator = new ConceptValidator,
        private readonly string $model = self::MODEL,
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
            // [DEAD 2026-08-19] $violations luon rong o luot duy nhat.
            // $correction = $violations === [] ? '' : $this->correction($violations)."\n\n";
            $correction = '';
            $response = $this->llm->complete(new LlmRequest(
                $correction.$instruction,
                $input,
                self::INSTRUCTION_VERSION,
                $this->model,
                maxTokens: 2500,
            ));

            try {
                $concept = $this->parser->parse($response->text)->canonicalised($profile);
                $result = $this->validator->validate($concept, $profile, $brief);

                // Retry CHI vi fatal. Rut gon van phong o luot sau thuong doi
                // cho dai sang truong khac chu khong lam concept dung hon.
                if (! $result->isFatal()) {
                    return new ConceptDesignResult($concept, $result->warnings, $attempt, $response->text);
                }

                $violations = $result->fatalViolations;
            } catch (InvalidCreativeConcept $exception) {
                $violations = $exception->violations;
            }
        }

        throw new InvalidCreativeConcept($violations, $response->text);
    }

    /** @param array<string, mixed> $spec */
    private function slotGuidance(array $spec): string
    {
        return isset($spec['guidance']) ? ' Guidance: '.$spec['guidance'] : '';
    }

    /** @param array<string, mixed> $spec */
    private function slotLine(string $name, array $spec): string
    {
        // Ngan sach tu suy tu chinh khe: 120 ky tu -> 17 tu, 60 -> 8. Mot
        // con so chung se tu sinh warning o cac khe ngan.
        $guidance = $this->slotGuidance($spec);

        if ($spec['type'] === 'enum') {
            return count($spec['values']) === 1
                ? "- {$name}: always {$spec['values'][0]}.{$guidance}"
                : "- {$name}: exactly one of: ".implode(', ', $spec['values']).'.'.$guidance;
        }

        return $spec['type'] === 'text'
            ? '- '.$name.': one compact technical phrase, at most '.max(3, intdiv((int) $spec['max_length'], 7)).' words.'.$guidance
            : "- {$name}: {$spec['type']}, between {$spec['min']} and {$spec['max']}.{$guidance}";
    }

    private function instruction(CategoryCreativeProfile $profile): string
    {
        $slots = [];
        foreach ($profile->identitySlots as $name => $spec) {
            if ($spec['type'] === 'object') {
                $lines = ['- '.$name.': an object with exactly these keys:'.$this->slotGuidance($spec)];
                foreach ($spec['fields'] as $field => $fieldSpec) {
                    $lines[] = '  '.$this->slotLine($field, $fieldSpec);
                }
                $slots[] = implode("\n", $lines);

                continue;
            }

            $slots[] = $this->slotLine($name, $spec);
        }

        $aspects = implode("\n", array_map(fn (string $aspect) => "- {$aspect}", $profile->inspectionAspects));
        $slotLines = implode("\n", $slots);
        $maxFeatures = ConceptValidator::MAX_FEATURES;

        // Bo hang neu ho so khong khai: mot tieu de rong day model di tim mot
        // danh sach khong ton tai.
        $antipatterns = $profile->conceptAntipatterns === [] ? '' : implode("\n", [
            'Avoid concept structures that require any of these antipatterns, unless the',
            'supplied source_insights explicitly require them:',
            ...array_map(fn (string $item) => "- {$item}", $profile->conceptAntipatterns),
            '',
            'design_thesis and governing_line must describe the unifying envelope of the',
            'whole object. massing_rhythm must explain how volumes merge, carve, overlap,',
            'or share that envelope.',
            '',
            '',
        ]);

        $forbidden = $profile->conceptForbiddenTerms === [] ? '' : implode("\n", [
            'These forms are refused outright. Do not use the words below, and do not',
            'restate the same shape in different words:',
            implode(', ', $profile->conceptForbiddenTerms).'.',
            '',
            '',
        ]);

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
        Good: "The response states one organising rule without imagery."

        The word budgets below are guidance, not a counting exercise. Staying near
        them keeps the specification readable.

        {$antipatterns}{$forbidden}Return ONLY one raw JSON object with exactly these fields:

        "design_thesis": one sentence, at most 24 words, stating the organising idea
        of the whole design.

        "design_identity": an object with exactly these keys:
        {$slotLines}

        Numeric bounds are hard design constraints. If the source material gives a
        value outside a bound, transform it into a new value inside the bound;
        never copy the out-of-range source value.

        An identity slot that counts repeated parts fixes that count as a
        VERIFICATION CONSTRAINT: the finished object must contain exactly that many.
        It is not the design motif. State the count once, in its own identity slot,
        and do not restate it in any other slot, in design_thesis, form_relationships,
        signature_features, or decisions. Never resolve a counted slot as that many
        separate stacked elements; the count must read inside one continuous form.

        Bad: "Five tiers step aft and inboard progressively; forward face of each
        tier is vertical."
        Why: this resolves a counted slot as that many separate objects, so the form
        reads as a stack rather than one mass.

        Counted levels must remain individually verifiable while belonging to one
        continuous primary mass. Do not describe them as independent repeated slabs.
        Do not copy the example's specific configuration, geometry, silhouette,
        orientation, numerical count or sentence structure. Examples demonstrate
        only the acceptance or rejection principle.

        "form_relationships": an object with exactly three fields, each at most
        30 words:
        - governing_line: the dominant line or geometry connecting the whole object
        - massing_rhythm: how the major volumes read as ONE continuous mass, where
          it swells, narrows, or is carved away. Describe volumes and the envelope
          containing them, never a count of levels
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
        A decision whose value came from replacing an out-of-range source number must
        use invented provenance: a bound produced that number, not the source, and
        scaling a rejected value is not the transformation this rule means.
        Do not write camera, lighting, rendering, provider, or prompt terminology.

        Return valid UTF-8 JSON only: no Markdown fences, prose, comments, trailing
        commas, null values, empty required strings or additional fields.
        TEXT;
    }

    /** @param list<string> $violations */
    // [DEAD 2026-08-19] MAX_ATTEMPTS = 1 nen khong con duong toi — xoa sau khi xong du an.
    //     private function correction(array $violations): string
    //     {
    //         $text = 'CORRECTION REQUIRED. The previous response was rejected for: '.implode('; ', $violations);
    //
    //         return mb_strlen($text) <= self::MAX_CORRECTION_CHARS
    //             ? $text
    //             : mb_substr($text, 0, self::MAX_CORRECTION_CHARS - 3).'...';
    //     }
}
