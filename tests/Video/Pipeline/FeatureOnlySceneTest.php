<?php

namespace Tests\Video\Pipeline;

use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\DirectorSelectionFailed;
use App\Video\Director\FakeDirector;
use App\Video\Extraction\CandidateGraphParser;
use App\Video\Extraction\FakeExtractor;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Pipeline\VideoPipelineFactory;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class FeatureOnlySceneTest extends TestCase
{
    private const CONTRACT_DIR = __DIR__.'/../../../contracts/renderplan/v1.0';

    private const ARTICLE = 'The ISA Amarcord 82 measures 82 metres. She carries a fold-out beach club aft. '
        .'An infinity pool sits on the sun deck. The yacht has five decks. Her hull is dark blue. '
        .'The tender Amarcord Junior measures 12 metres. It carries a folding boarding ladder. '
        .'Its hull is white. It has two decks. '
        .'Marina di Portofino has 60 berths. Its quay is granite.';

    private const HERO = <<<'JSON'
    {
      "id": "isa_amarcord", "type": "vehicle",
      "name": "ISA Amarcord 82", "name_quote": "ISA Amarcord 82",
      "claims": [
        { "attribute": "length_metres", "value": "82 metres", "evidence_quote": "82 metres" },
        { "attribute": "beach_club", "value": "fold-out beach club", "evidence_quote": "fold-out beach club" },
        { "attribute": "pool_feature", "value": "infinity pool", "evidence_quote": "infinity pool" },
        { "attribute": "deck_count", "value": "five decks", "evidence_quote": "five decks" },
        { "attribute": "hull_colour", "value": "dark blue", "evidence_quote": "dark blue" }
      ]
    }
    JSON;

    private const TENDER = <<<'JSON'
    {
      "id": "amarcord_junior", "type": "vehicle",
      "name": "Amarcord Junior", "name_quote": "Amarcord Junior",
      "claims": [
        { "attribute": "length_metres", "value": "12 metres", "evidence_quote": "12 metres" },
        { "attribute": "boarding_ladder", "value": "folding boarding ladder", "evidence_quote": "folding boarding ladder" },
        { "attribute": "hull_colour", "value": "white", "evidence_quote": "white" },
        { "attribute": "deck_count", "value": "two decks", "evidence_quote": "two decks" }
      ]
    }
    JSON;

    private const MARINA = <<<'JSON'
    {
      "id": "marina_portofino", "type": "building",
      "name": "Marina di Portofino", "name_quote": "Marina di Portofino",
      "claims": [
        { "attribute": "berth_count", "value": "60 berths", "evidence_quote": "60 berths" },
        { "attribute": "quay_material", "value": "granite", "evidence_quote": "granite" }
      ]
    }
    JSON;

    private const PRODUCER = <<<'JSON'
    {
      "target_audience": "yacht readers",
      "core_conflict": "scale made intimate",
      "visual_promise": "a walk along her decks",
      "emotional_curve": ["calm", "wonder"]
    }
    JSON;

    /** @var list<array{code: string, context: array<string, mixed>}> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->warnings = [];
    }

    private function extraction(): string
    {
        return sprintf('{"entities": [%s, %s], "relations": [], "events": []}', self::HERO, self::TENDER);
    }

    private function extractionWithTwoDetailScenes(): string
    {
        return sprintf(
            '{"entities": [%s, %s, %s], "relations": [], "events": []}',
            self::HERO,
            self::TENDER,
            self::MARINA,
        );
    }

    /** @param list<string> $directorResponses */
    private function llm(string $extraction, array $directorResponses): LlmClient
    {
        return new class($extraction, self::PRODUCER, $directorResponses) implements LlmClient
        {
            /** @var list<LlmRequest> */
            public array $requests = [];

            private int $directorCalls = 0;

            public function __construct(
                private readonly string $extraction,
                private readonly string $producer,
                private readonly array $directorResponses,
            ) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->requests[] = $request;

                $text = match ($request->instructionVersion) {
                    'extract-v4' => $this->extraction,
                    'producer-v1' => $this->producer,
                    default => $this->directorResponses[$this->directorCalls++] ?? '{}',
                };

                return new LlmResponse($text, 'haiku');
            }
        };
    }

    private function picksFirstFeature(string $hero, string $composition, string $newInformation): string
    {
        return json_encode([
            'hero' => $hero,
            'primary_index' => null,
            'feature_indices' => [0],
            'emotion' => 'wonder',
            'reveal' => 'immediate',
            'composition_note' => $composition,
            'new_information' => $newInformation,
        ]);
    }

    private function picksTheBeachClub(): string
    {
        return $this->picksFirstFeature(
            'isa_amarcord',
            'the beach club opens out over the water',
            'the aft deck folds down to the waterline',
        );
    }

    private function picksTheBoardingLadder(): string
    {
        return $this->picksFirstFeature(
            'amarcord_junior',
            'the ladder hangs off the tender flank',
            'the tender can be boarded from the water',
        );
    }

    /**
     * @param  list<string>  $directorResponses
     * @return array{0: array<string, mixed>, 1: LlmClient}
     */
    private function planWith(array $directorResponses, ?string $extraction = null, bool $observeWarnings = true): array
    {
        $llm = $this->llm($extraction ?? $this->extraction(), $directorResponses);

        $plan = (new VideoPipelineFactory)->claude(
            $llm,
            [],
            $observeWarnings
                ? function (string $code, array $context): void {
                    $this->warnings[] = ['code' => $code, 'context' => $context];
                }
            : null,
        )->plan(
            new RawArticle('art_isa', 'ISA Amarcord 82', self::ARTICLE),
            new RenderPlanMeta(
                '0198f3a1-4b2c-4d3e-8f10-2a3b4c5d6e7f',
                '7c9e6679-7425-40de-944b-e07fc1f90ae7',
                'ISA Amarcord 82',
                'en',
                '2026-08-13T00:00:00Z',
            ),
        );

        return [$plan, $llm];
    }

    /** @return list<LlmRequest> */
    private function directorRequests(LlmClient $llm): array
    {
        return array_values(array_filter(
            $llm->requests,
            fn (LlmRequest $r) => $r->instructionVersion === 'director-v3',
        ));
    }

    /** @return array<string, mixed> */
    private function requireDetailScene(array $plan, int $nth = 0): array
    {
        $scenes = array_values(array_filter(
            $plan['scenes'],
            fn (array $scene) => ($scene['purpose'] ?? null) === 'DETAIL',
        ));

        $this->assertArrayHasKey($nth, $scenes, 'fixture phải sinh ra scene DETAIL thứ '.($nth + 1));

        return $scenes[$nth];
    }

    private function assertPlanMatchesSchema(array $plan): void
    {
        $result = (new Validator)->validate(
            json_decode(json_encode($plan), false, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents(self::CONTRACT_DIR.'/schema.json'), false, 512, JSON_THROW_ON_ERROR),
        );

        if ($result->hasError()) {
            $this->fail(json_encode(
                (new ErrorFormatter)->format($result->error()),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_fixture_really_has_no_action_to_tell(): void
    {
        [$plan, $llm] = $this->planWith([$this->picksTheBeachClub()]);

        $this->assertSame([], $plan['world']['relations']);
        $this->assertSame([], $plan['world']['events']);

        $input = $this->directorRequests($llm)[0]->input;
        $start = strpos($input, 'ACTION CANDIDATES');
        $end = strpos($input, 'FEATURE CANDIDATES');

        $this->assertIsInt($start, 'prompt phải có khối ACTION CANDIDATES');
        $this->assertIsInt($end, 'prompt phải có khối FEATURE CANDIDATES');
        $this->assertDoesNotMatchRegularExpression('/^\[\d+\]/m', substr($input, $start, $end - $start));
    }

    public function test_the_production_factory_runs_the_director_with_features_on(): void
    {
        [, $llm] = $this->planWith([$this->picksTheBeachClub()]);

        $requests = $this->directorRequests($llm);

        $this->assertCount(1, $requests, 'scene chỉ có thuộc tính phải được đưa tới Director');
        $this->assertSame('director-v3', $requests[0]->instructionVersion);
        $this->assertStringContainsString('"feature_indices": [],', $requests[0]->instruction);
        $this->assertStringContainsString(
            '[0] ISA Amarcord 82: beach_club = fold-out beach club',
            $requests[0]->input,
        );
    }

    public function test_a_feature_only_scene_now_carries_director_notes(): void
    {
        [$plan] = $this->planWith([$this->picksTheBeachClub()]);

        $this->assertArrayHasKey('director_notes', $this->requireDetailScene($plan));
    }

    public function test_the_selected_feature_is_the_exact_canonical_candidate(): void
    {
        [$plan] = $this->planWith([$this->picksTheBeachClub()]);

        $this->assertSame(
            [['entity' => 'isa_amarcord', 'attribute' => 'beach_club', 'values' => ['fold-out beach club']]],
            $this->requireDetailScene($plan)['director_notes']['features'],
        );
    }

    public function test_a_scene_without_an_action_omits_primary_and_micro_physics(): void
    {
        [$plan] = $this->planWith([$this->picksTheBeachClub()]);

        $notes = $this->requireDetailScene($plan)['director_notes'];

        $this->assertArrayNotHasKey('primary', $notes);
        $this->assertSame([], $notes['micro_physics']);
        $this->assertSame('the beach club opens out over the water', $notes['composition_note']);
    }

    public function test_the_finished_plan_still_passes_the_real_schema(): void
    {
        [$plan] = $this->planWith([$this->picksTheBeachClub()]);

        $this->assertPlanMatchesSchema($plan);
    }

    public function test_a_scene_the_director_cannot_answer_warns_exactly_once(): void
    {
        [$plan] = $this->planWith(['{}', '{}']);

        $this->assertCount(1, $this->warnings);
        $this->assertSame('DIRECTOR_SELECTION_FAILED', $this->warnings[0]['code']);
        $this->assertArrayNotHasKey('director_notes', $this->requireDetailScene($plan));
    }

    public function test_the_warning_payload_carries_nothing_but_stable_metadata(): void
    {
        $this->planWith(['{}', '{}']);

        $context = $this->warnings[0]['context'];

        $this->assertSame(['scene_id', 'scene_ordinal', 'reason', 'attempts'], array_keys($context));
        $this->assertSame(DirectorSelectionFailed::REASON_NO_VALID_INDEX_AFTER_RETRY, $context['reason']);
        $this->assertSame(2, $context['attempts']);
        $this->assertIsInt($context['scene_ordinal']);
    }

    public function test_one_failed_scene_does_not_prevent_the_next_scene_from_being_directed(): void
    {
        [$plan, $llm] = $this->planWith(
            ['{}', '{}', $this->picksTheBoardingLadder()],
            $this->extractionWithTwoDetailScenes(),
        );

        $this->assertCount(3, $this->directorRequests($llm));
        $this->assertCount(1, $this->warnings);

        $this->assertArrayNotHasKey('director_notes', $this->requireDetailScene($plan, 0));
        $this->assertSame(
            [['entity' => 'amarcord_junior', 'attribute' => 'boarding_ladder', 'values' => ['folding boarding ladder']]],
            $this->requireDetailScene($plan, 1)['director_notes']['features'],
        );

        $this->assertPlanMatchesSchema($plan);
    }

    public function test_the_retry_tells_the_model_which_invariant_it_broke(): void
    {
        [, $llm] = $this->planWith(['{}', $this->picksTheBeachClub()]);

        $requests = $this->directorRequests($llm);

        $this->assertStringNotContainsString('YOUR PREVIOUS RESPONSE WAS REJECTED', $requests[0]->input);
        $this->assertStringContainsString('YOUR PREVIOUS RESPONSE WAS REJECTED', $requests[1]->input);
        $this->assertStringContainsString('hero was not one of the HERO CANDIDATES', $requests[1]->input);
    }

    public function test_a_successful_retry_warns_about_nothing(): void
    {
        [$plan, $llm] = $this->planWith(['{}', $this->picksTheBeachClub()]);

        $this->assertSame([], $this->warnings);
        $this->assertCount(2, $this->directorRequests($llm));
        $this->assertArrayHasKey('director_notes', $this->requireDetailScene($plan));
    }

    public function test_the_free_fake_path_survives_a_feature_only_scene(): void
    {
        // Đúng ActionSelection mà video:benchmark --extractor=fake truyền vào.
        // Nó trỏ tới action index 0, thứ không tồn tại ở scene chỉ có thuộc tính.
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor((new CandidateGraphParser)->parse($this->extraction())),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', ['calm'])),
            new FakeDirector(new ActionSelection('', 0, [], 'calm', 'immediate')),
        );

        $plan = $pipeline->plan(
            new RawArticle('art_isa', 'ISA Amarcord 82', self::ARTICLE),
            new RenderPlanMeta(
                '0198f3a1-4b2c-4d3e-8f10-2a3b4c5d6e7f',
                '7c9e6679-7425-40de-944b-e07fc1f90ae7',
                'ISA Amarcord 82',
                'en',
                '2026-08-13T00:00:00Z',
            ),
        );

        $this->assertArrayHasKey('features', $this->requireDetailScene($plan)['director_notes']);
        $this->assertPlanMatchesSchema($plan);
    }

    public function test_the_pipeline_behaves_the_same_without_an_observer(): void
    {
        [$withObserver] = $this->planWith(['{}', '{}']);
        [$without] = $this->planWith(['{}', '{}'], observeWarnings: false);

        $this->assertSame($withObserver, $without);
    }
}
