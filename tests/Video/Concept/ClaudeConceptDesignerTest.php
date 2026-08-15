<?php

namespace Tests\Video\Concept;

use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\InvalidCreativeConcept;
use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;

class ClaudeConceptDesignerTest extends TestCase
{
    private function profile(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'vehicle', 'Extract inspiration.', ['design_profile'], ['form', 'materials'], ['brand'],
            ['ratio' => ['type' => 'number', 'min' => 2.0, 'max' => 12.0]],
            'Design a new vehicle whose silhouette is readable from outside.',
        );
    }

    public function test_it_uses_sonnet_and_returns_a_validated_relationship_aware_concept(): void
    {
        $requests = [];
        $llm = new class($requests) implements LlmClient
        {
            public function __construct(private array &$requests) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->requests[] = $request;

                return new LlmResponse(json_encode([
                    'design_thesis' => 'One line connects the whole vehicle.',
                    'design_identity' => ['ratio' => 6.0],
                    'form_relationships' => [
                        'governing_line' => 'A single line runs from front to rear.',
                        'massing_rhythm' => 'Volumes taper progressively.',
                        'feature_integration' => 'Features grow from the main structure.',
                    ],
                    'signature_features' => [[
                        'description' => 'A recessed observation deck.',
                        'visible_from' => ['side'],
                    ]],
                    'decisions' => [
                        ['aspect' => 'form', 'provenance' => 'invented', 'decision' => 'Use a low continuous profile.'],
                        ['aspect' => 'materials', 'provenance' => 'invented', 'decision' => 'Use aluminium and glass.'],
                    ],
                ], JSON_THROW_ON_ERROR), 'sonnet');
            }
        };

        $result = (new ClaudeConceptDesigner($llm))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $this->assertSame('sonnet', $requests[0]->model);
        $this->assertSame('concept-v2', $requests[0]->instructionVersion);
        $this->assertStringContainsString('form_relationships', $requests[0]->instruction);
        $this->assertSame('Volumes taper progressively.', $result->concept->formRelationships->massingRhythm);
    }

    /** @param array<string, mixed> $overrides */
    private function llmReturning(array $overrides, array &$requests): LlmClient
    {
        return new class($overrides, $requests) implements LlmClient
        {
            public function __construct(private array $overrides, private array &$requests) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->requests[] = $request;

                return new LlmResponse(json_encode(array_replace([
                    'design_thesis' => 'One line connects the whole vehicle.',
                    'design_identity' => ['ratio' => 6.0],
                    'form_relationships' => [
                        'governing_line' => 'A single line runs from front to rear.',
                        'massing_rhythm' => 'Volumes taper progressively.',
                        'feature_integration' => 'Features grow from the main structure.',
                    ],
                    'signature_features' => [[
                        'description' => 'A recessed observation deck.',
                        'visible_from' => ['side'],
                    ]],
                    'decisions' => [
                        ['aspect' => 'form', 'provenance' => 'invented', 'decision' => 'Use a low continuous profile.'],
                        ['aspect' => 'materials', 'provenance' => 'invented', 'decision' => 'Use aluminium and glass.'],
                    ],
                ], $this->overrides), JSON_THROW_ON_ERROR), 'sonnet');
            }
        };
    }

    public function test_a_verbose_but_valid_concept_is_returned_without_a_second_attempt(): void
    {
        // Luot v2 that: attempt 1 chi verbose, khong sai nghia — hoi lai lan hai
        // ton them $0.030 ma khong lam concept dung hon.
        $requests = [];
        $llm = $this->llmReturning([
            'decisions' => [
                ['aspect' => 'form', 'provenance' => 'invented', 'decision' => str_repeat('a', 300)],
                ['aspect' => 'materials', 'provenance' => 'invented', 'decision' => 'Use aluminium and glass.'],
            ],
        ], $requests);

        $result = (new ClaudeConceptDesigner($llm))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $this->assertCount(1, $requests);
        $this->assertSame(1, $result->attempts);
        $this->assertSame(
            [['code' => 'PROSE_EXCEEDS_RECOMMENDED_LENGTH', 'field' => 'decisions[0].decision', 'actual' => 300, 'recommended' => 260]],
            $result->warningsToArray(),
        );
    }

    public function test_prose_past_the_storage_ceiling_retries_and_then_fails(): void
    {
        $requests = [];
        $llm = $this->llmReturning([
            'decisions' => [
                ['aspect' => 'form', 'provenance' => 'invented', 'decision' => str_repeat('a', 1001)],
                ['aspect' => 'materials', 'provenance' => 'invented', 'decision' => 'Use aluminium and glass.'],
            ],
        ], $requests);

        try {
            (new ClaudeConceptDesigner($llm))->design(
                new InspirationBrief(['design_profile'], 'A source.', [], []),
                $this->profile(),
            );
            $this->fail('the storage ceiling must be fatal');
        } catch (InvalidCreativeConcept $e) {
            $this->assertStringContainsString('storage ceiling', implode(' ', $e->violations));
        }

        $this->assertCount(2, $requests);
        $this->assertStringContainsString('CORRECTION REQUIRED', $requests[1]->instruction);
    }

    public function test_the_correction_carries_fatal_violations_only(): void
    {
        $requests = [];
        $llm = $this->llmReturning([
            'design_identity' => ['ratio' => 99.0],
            'decisions' => [
                ['aspect' => 'form', 'provenance' => 'invented', 'decision' => str_repeat('a', 300)],
                ['aspect' => 'materials', 'provenance' => 'invented', 'decision' => 'Use aluminium and glass.'],
            ],
        ], $requests);

        try {
            (new ClaudeConceptDesigner($llm))->design(
                new InspirationBrief(['design_profile'], 'A source.', [], []),
                $this->profile(),
            );
        } catch (InvalidCreativeConcept) {
            // Chi quan tam correction da gui gi.
        }

        $this->assertStringContainsString('design_identity.ratio', $requests[1]->instruction);
        $this->assertStringNotContainsString('decisions[0]', $requests[1]->instruction);
    }

    public function test_the_shared_instruction_carries_no_category_vocabulary(): void
    {
        $requests = [];
        (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        foreach (['yacht', 'hull', 'superstructure', 'glazing', 'vessel', 'deck'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $requests[0]->instruction);
        }
    }
}
