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
            ['front_three_quarter' => 'Low camera near the waterline off the bow.',
                'side' => 'Low camera near the waterline, square to the centreline.',
                'rear_three_quarter' => 'Low camera near the waterline off the quarter.'],
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

        $this->assertSame(ClaudeConceptDesigner::MODEL, $requests[0]->model);
        $this->assertSame('sonnet5', ClaudeConceptDesigner::MODEL);
        $this->assertSame('concept-v19', $requests[0]->instructionVersion);
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

    public function test_prose_past_the_storage_ceiling_is_fatal(): void
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

        $this->assertCount(1, $requests);
    }

    public function test_the_instruction_version_matches_what_the_instruction_now_asks_for(): void
    {
        $requests = [];
        (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $instruction = $requests[0]->instruction;

        $this->assertSame('concept-v19', ClaudeConceptDesigner::INSTRUCTION_VERSION);
        $this->assertStringContainsString('compact technical specification language', $instruction);
        $this->assertStringContainsString('Bad:', $instruction);
        $this->assertStringContainsString('Good:', $instruction);
        $this->assertStringContainsString('at most 24 words', $instruction);
        $this->assertStringNotContainsString('characters', $instruction);
    }

    /**
     * Ba mau thuan do duoc tren luot concept-v3 da tra tien: 4 tang canh "Three
     * stepped volumes"; feature_integration goi ten "infinity pool" va "wellness
     * balcony" khong he co trong signature_features; mot feature khai nhin thay
     * "on both sides simultaneously" tu goc ba-phan-tu.
     *
     * Test nay khoa viec instruction CO HOI, khong khoa viec model CO TUAN.
     *
     * @dataProvider semanticRules
     */
    public function test_the_instruction_asks_for_the_three_coherence_rules(string $needle): void
    {
        $requests = [];
        (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $this->assertStringContainsString($needle, $requests[0]->instruction);
    }

    public static function semanticRules(): array
    {
        return [
            'count is a check not a motif' => ['VERIFICATION CONSTRAINT'],
            'count is not the motif' => ['not the design motif'],
            'count reads inside one form' => ['inside one continuous form'],
            'the failing sentence is shown' => ['Five tiers step aft'],
            'the example says why' => ['reads as a stack rather than one mass'],
            'principle only' => ['never name an individual feature here'],
            'materially readable' => ['materially readable from that'],
            'self-occlusion allowed' => ['self-occlusion is allowed'],
            'no mutually occluded claim' => ['must not require mutually'],
        ];
    }

    public function test_an_identity_word_budget_follows_the_slot_it_belongs_to(): void
    {
        $profile = new CategoryCreativeProfile(
            'vehicle', 'Extract inspiration.', ['design_profile'], ['form'], ['brand'],
            [
                'silhouette' => ['type' => 'text', 'max_length' => 120],
                'paint' => [
                    'type' => 'text',
                    'max_length' => 60,
                    'guidance' => 'State hue and finish.',
                ],
            ],
            'Design a new vehicle whose silhouette is readable from outside.',
            ['front_three_quarter' => 'Low camera near the waterline off the bow.',
                'side' => 'Low camera near the waterline, square to the centreline.',
                'rear_three_quarter' => 'Low camera near the waterline off the quarter.'],
        );

        $requests = [];
        try {
            (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
                new InspirationBrief(['design_profile'], 'A source.', [], []),
                $profile,
            );
        } catch (InvalidCreativeConcept) {
            // Chi quan tam instruction da dung so tu nao.
        }

        $this->assertStringContainsString('silhouette: one compact technical phrase, at most 17 words', $requests[0]->instruction);
        $this->assertStringContainsString('paint: one compact technical phrase, at most 8 words', $requests[0]->instruction);
        $this->assertStringContainsString('Guidance: State hue and finish.', $requests[0]->instruction);
    }

    public function test_every_camera_description_reaches_the_model(): void
    {
        $requests = [];
        (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        foreach ($this->profile()->viewpointGuidance as $name => $text) {
            $this->assertStringContainsString("- {$name}: {$text}", $requests[0]->instruction);
        }
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

    public function test_the_model_is_told_what_a_counted_slot_must_not_become(): void
    {
        // Luat truu tuong thua ky luat khi co vi du. `extract-v4` (2026-08-07) do
        // duoc dieu do bang tien: 3 vi du BAD+WHY dua survival tu 80.6% len 92.9%.
        //
        // Ve BAD la dau ra THAT da tra tien o concept-v9, va no o lai. Ve GOOD thi
        // bi go o v12: no ve san mot con tau thay vi neu mot quan he.
        $instruction = $this->instructionFor($this->profile());

        $this->assertStringContainsString('Bad: "Five tiers step aft', $instruction);
        $this->assertStringContainsString('Why: this resolves a counted slot', $instruction);
        $this->assertStringNotContainsString('Good: "One continuous wedge-like', $instruction);
    }

    public function test_the_word_that_asked_for_the_wedding_cake_is_gone(): void
    {
        // `massing_rhythm: how major volumes transition, TAPER, overlap or repeat`
        // la chu cua chinh ta. Sonnet tra ve "a consistent taper" — dung yeu cau.
        $this->assertStringNotContainsString('taper', $this->instructionFor($this->profile()));
    }

    public function test_a_profile_that_declares_forbidden_forms_puts_them_in_front_of_the_model(): void
    {
        $instruction = $this->instructionFor($this->profileWithAntipatterns());

        $this->assertStringContainsString('unless the', $instruction);
        $this->assertStringContainsString('supplied source_insights explicitly require them:', $instruction);
        $this->assertStringContainsString('- stacked like a wedding cake', $instruction);
        $this->assertStringContainsString('- apartment-block massing', $instruction);
    }

    public function test_a_profile_with_no_forbidden_forms_gets_no_empty_heading(): void
    {
        // Mot tieu de rong day model di tim mot danh sach khong ton tai.
        $instruction = $this->instructionFor($this->profile());

        $this->assertStringNotContainsString('antipatterns', $instruction);
        $this->assertStringNotContainsString('


', $instruction);
    }

    public function test_the_counted_slot_carries_its_rule_where_the_model_reads_it(): void
    {
        // Luat cach khe 15 dong yeu hon chu giai dinh ngay tren khe.
        $instruction = $this->instructionFor($this->profileWithAntipatterns());

        $this->assertStringContainsString(
            'tier_count: integer, between 1 and 10. Guidance: A verification count', $instruction);
    }

    public function test_sonnet_is_asked_for_the_same_answer_twice_running(): void
    {
        // 0.7 cho hai concept khac nhau tu cung mot bai. Anh neo phai lap lai duoc.
        $requests = [];
        (new ClaudeConceptDesigner($this->llmReturning([], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $this->profile(),
        );

        $this->assertNull($requests[0]->temperature);
        $this->assertSame('concept-v19', $requests[0]->instructionVersion);
    }

    public function test_the_model_is_told_what_to_do_when_the_source_is_out_of_range(): void
    {
        // Bon lan Sonnet bam so nguon roi roi ra ngoai khoang. Khoang tu no khong
        // day duoc — no chi tu choi. Cau nay bao model phai BIEN DOI, va no bao
        // cho MOI khe so, khong rieng chieu dai.
        $instruction = $this->instructionFor($this->profile());

        $this->assertStringContainsString('Numeric bounds are hard design constraints', $instruction);
        $this->assertStringContainsString('never copy the out-of-range source value', $instruction);
    }

    public function test_the_refusal_carries_the_answer_that_was_paid_for(): void
    {
        // Khong co ve nay thi runner khong con gi de luu: 2026-08-23 mot luot
        // concept fail voi cost_usd = 0.0269 va raw_response = NULL.
        $requests = [];
        $llm = $this->llmReturning(['design_identity' => ['ratio' => 999.0]], $requests);

        try {
            (new ClaudeConceptDesigner($llm))->design(
                new InspirationBrief(['design_profile'], 'A source.', [], []),
                $this->profile(),
            );
            $this->fail('Gia tri ngoai khoang phai bi tu choi');
        } catch (InvalidCreativeConcept $exception) {
            $this->assertStringContainsString('999', $exception->rawResponse);
            $this->assertNotSame([], $exception->violations);
        }
    }

    public function test_the_instruction_shows_a_relationship_without_drawing_the_object(): void
    {
        // concept-v11 tra ve "One continuous wedge-like upper envelope tapers aft
        // inside a single swept volume." — trung 13/19 tu voi vi du Good cua chinh
        // instruction. Model khong hoc NGUYEN TAC, no chep KHUON, va moi bai bao
        // khac nhau se cung hoi tu ve mot con tau.
        $instruction = $this->instructionFor($this->profile());

        foreach (['wedge', 'swept volume', 'horizontal bands'] as $shape) {
            $this->assertStringNotContainsString($shape, $instruction, $shape);
        }

        $this->assertStringContainsString('Counted levels must remain individually verifiable', $instruction);
        $this->assertStringContainsString('only the acceptance or rejection principle', $instruction);
    }

    public function test_a_number_the_bounds_invented_may_not_claim_the_source_made_it(): void
    {
        // Bai nguon noi 120-foot (36,6 m); san bien tap keo len 78 m. v11 VA v12 deu
        // dan nhan `inspired` — truy nguyen bang may se hieu 78 m la du kien tu bai.
        //
        // v12 that bai vi DAT SAI CHO: luat nam o khoi `design_identity`, cach 60
        // dong, trong khi mot luat nguoc chieu nam ngay duoi khoi `decisions`:
        // "An inspired decision must TRANSFORM the reference". Sonnet viet
        // "transforming the 120-foot source" — dung dinh nghia `inspired` do.
        //
        // v13 dat ngoai le NGAY CANH luat kia va goi ten xung dot.
        $instruction = $this->instructionFor($this->profile());
        $decisions = substr($instruction, strpos($instruction, 'An inspired decision'));

        $this->assertStringContainsString('replacing an out-of-range source number', $decisions);
        $this->assertStringContainsString('a bound produced that number, not the source', $decisions);
        $this->assertStringContainsString('not the transformation this rule means', $decisions);

        // Va no khong duoc con ban sao o cho cu.
        $bounds = substr($instruction, strpos($instruction, 'Numeric bounds'));
        $bounds = substr($bounds, 0, strpos($bounds, '

'));
        $this->assertStringNotContainsString('provenance', $bounds);
    }

    private function profileWithAntipatterns(): CategoryCreativeProfile
    {
        return new CategoryCreativeProfile(
            'vehicle', 'Extract inspiration.', ['design_profile'], ['form', 'materials'], ['brand'],
            ['tier_count' => ['type' => 'integer', 'min' => 1, 'max' => 10,
                'guidance' => 'A verification count, not a design motif. State it here and nowhere else.']],
            'Design a new vehicle whose silhouette is readable from outside.',
            ['front_three_quarter' => 'Low camera off the bow.',
                'side' => 'Low camera square to the centreline.',
                'rear_three_quarter' => 'Low camera off the quarter.'],
            [], [],
            ['stacked like a wedding cake', 'apartment-block massing'],
        );
    }

    private function instructionFor(CategoryCreativeProfile $profile): string
    {
        // Payload gia phai khop KHE cua ho so duoc truyen vao, khong phai mot
        // ten khe doan truoc: helper nay chay tren nhieu ho so khac nhau.
        $requests = [];
        $identity = [];

        foreach ($profile->identitySlots as $name => $spec) {
            $identity[$name] = $spec['type'] === 'text' ? 'a compact value' : $spec['min'];
        }

        (new ClaudeConceptDesigner($this->llmReturning(['design_identity' => $identity], $requests)))->design(
            new InspirationBrief(['design_profile'], 'A source.', [], []),
            $profile,
        );

        return $requests[0]->instruction;
    }
}
