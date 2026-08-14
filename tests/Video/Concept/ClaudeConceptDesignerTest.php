<?php

namespace Tests\Video\Concept;

use App\Video\Concept\ClaudeConceptDesigner;
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

        $concept = (new ClaudeConceptDesigner($llm))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $this->assertSame('sonnet', $requests[0]->model);
        $this->assertSame('concept-v2', $requests[0]->instructionVersion);
        $this->assertStringContainsString('form_relationships', $requests[0]->instruction);
        $this->assertSame('Volumes taper progressively.', $concept->formRelationships->massingRhythm);
    }
}
