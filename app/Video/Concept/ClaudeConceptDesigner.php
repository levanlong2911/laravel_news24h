<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;

final class ClaudeConceptDesigner
{
    public const INSTRUCTION_VERSION = 'concept-v2';

    private const MAX_ATTEMPTS = 2;

    private const MAX_CORRECTION_CHARS = 1200;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly CreativeConceptParser $parser = new CreativeConceptParser,
        private readonly ConceptValidator $validator = new ConceptValidator,
        private readonly string $model = 'sonnet',
    ) {}

    public function design(InspirationBrief $brief, CategoryCreativeProfile $profile): CreativeConcept
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
                $violations = $this->validator->violations($concept, $profile, $brief);
                if ($violations === []) {
                    return $concept;
                }
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
            $slots[] = $spec['type'] === 'text'
                ? "- {$name}: text, at most {$spec['max_length']} characters"
                : "- {$name}: {$spec['type']}, between {$spec['min']} and {$spec['max']}";
        }

        $aspects = implode("\n", array_map(fn (string $aspect) => "- {$aspect}", $profile->inspectionAspects));
        $slotLines = implode("\n", $slots);
        $maxThesis = ConceptValidator::MAX_THESIS;
        $maxRelationship = ConceptValidator::MAX_FORM_RELATIONSHIP;
        $maxFeatures = ConceptValidator::MAX_FEATURES;
        $maxFeatureDescription = ConceptValidator::MAX_FEATURE_DESCRIPTION;
        $maxDecision = ConceptValidator::MAX_DECISION;

        return <<<TEXT
        You are a concept designer. The material below comes from an existing product,
        but you are designing a NEW object that has never existed.

        {$profile->conceptMission}

        Never name a real company, designer, builder, brand, engine maker, owner, or
        existing product. Do not name the new design.

        Return ONLY one raw JSON object with exactly these fields:

        "design_thesis": one sentence, at most {$maxThesis} characters,
        stating the organising idea of the whole design.

        "design_identity": an object with exactly these keys:
        {$slotLines}

        "form_relationships": an object with exactly three fields, each at most
        {$maxRelationship} characters:
        - governing_line: the dominant line or geometry connecting the whole object
        - massing_rhythm: how major volumes transition, taper, overlap, or repeat
        - feature_integration: how signature features grow from the main form instead
          of looking attached afterward

        "signature_features": 1 to {$maxFeatures} objects. Each has
        "description" (one visible feature, at most {$maxFeatureDescription} characters)
        and "visible_from" (one or more of: front_three_quarter, side,
        rear_three_quarter).

        "decisions": exactly one object for every aspect below. Each object has
        "aspect", "provenance" (inspired|invented), and "decision" (at most
        {$maxDecision} characters).
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
