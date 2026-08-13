<?php

namespace Tests\Video\Director;

use App\Video\Director\ClaudeDirector;
use App\Video\Editorial\ActionCandidate;
use App\Video\Editorial\ActionType;
use App\Video\Editorial\FeatureCandidate;
use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Producer\ProducerOutput;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Identity;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * `new_information` từng bị nuốt im lặng: instruction() bắt Claude trả field
 * này và dựng cả mục CONTINUITY RULES quanh nó, nhưng parse() không đọc và
 * PREVIOUS SCENES không mang nó sang scene sau — nên luật "khác mọi cảnh
 * trước" không thể tuân được kể cả khi Claude muốn.
 */
class ClaudeDirectorTest extends TestCase
{
    /** Ghi lại request cuối để soi context, trả về text cố định. */
    private function llm(string $responseText): LlmClient
    {
        return new class($responseText) implements LlmClient
        {
            public ?LlmRequest $lastRequest = null;

            public function __construct(private readonly string $text) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->lastRequest = $request;

                return new LlmResponse($this->text, 'haiku', 10, 5, 100, 0.0001);
            }
        };
    }

    private function world(): VerifiedWorldGraph
    {
        $evidence = new Evidence('Amadea', EvidenceSource::Body, 0, ProvenanceLevel::Direct);

        return new VerifiedWorldGraph([
            new Entity('yacht', EntityType::Vehicle, [], new Identity('Amadea', true, $evidence)),
        ], [], []);
    }

    /** @return array<string, mixed> */
    private function candidates(bool $withFeatures = false, bool $withActions = true): array
    {
        return [
            'hero_candidates' => ['yacht'],
            'action_candidates' => $withActions ? [new ActionCandidate(ActionType::Lift, 'yacht')] : [],
            'feature_candidates' => $withFeatures
                ? [
                    new FeatureCandidate('yacht', 'pool_feature', ['infinity pool']),
                    new FeatureCandidate('yacht', 'amenity', ['spa', 'helipad']),
                ]
                : [],
        ];
    }

    private function select(LlmClient $llm, array $priorScenes = []): \App\Video\Director\ActionSelection
    {
        return (new ClaudeDirector($llm))->select(
            $this->candidates(withFeatures: true),
            $this->world(),
            new ProducerOutput('audience', 'conflict', 'promise', []),
            1,
            1,
            $priorScenes,
        );
    }

    public function test_new_information_is_read_from_the_response(): void
    {
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'secondary_indices' => [],
            'emotion' => 'wonder',
            'reveal' => 'immediate',
            'composition_note' => 'the hull fills the frame',
            'new_information' => 'the viewer sees the vessel at full scale',
        ]));

        $selection = $this->select($llm);

        $this->assertSame('the viewer sees the vessel at full scale', $selection->newInformation);
    }

    public function test_a_response_without_new_information_still_parses(): void
    {
        // Runner/model cũ chưa trả field này — không được làm hỏng cả scene.
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'emotion' => 'wonder',
            'reveal' => 'immediate',
            'composition_note' => 'the hull fills the frame',
        ]));

        $selection = $this->select($llm);

        $this->assertSame('', $selection->newInformation);
        $this->assertSame('the hull fills the frame', $selection->compositionNote);
    }

    public function test_a_non_string_new_information_does_not_crash(): void
    {
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'new_information' => 123,
        ]));

        $this->assertSame('123', $this->select($llm)->newInformation);
    }

    public function test_previous_scenes_carry_new_information_into_the_prompt(): void
    {
        $llm = $this->llm('{}');

        $this->select($llm, [[
            'ordinal' => 1,
            'hero' => 'yacht',
            'emotion' => 'calm',
            'composition_note' => 'wide on the hull',
            'new_information' => 'the vessel is 106 metres long',
        ]]);

        $this->assertStringContainsString(
            'new_information="the vessel is 106 metres long"',
            $llm->lastRequest->input,
        );
    }

    public function test_a_prior_scene_without_new_information_does_not_crash(): void
    {
        // Log của bản cũ không có key này — Director vẫn phải chạy.
        $llm = $this->llm('{}');

        $this->select($llm, [[
            'ordinal' => 1,
            'hero' => 'yacht',
            'emotion' => 'calm',
            'composition_note' => 'wide on the hull',
        ]]);

        $this->assertStringContainsString('new_information=""', $llm->lastRequest->input);
    }

    public function test_the_instruction_asks_for_a_field_the_parser_actually_reads(): void
    {
        // Bug gốc: instruction đòi new_information mà parse() không đọc — trả
        // tiền token cho một câu bị vứt ngay khi nhận.
        $llm = $this->llm('{}');
        $this->select($llm);

        $this->assertStringContainsString('new_information', $llm->lastRequest->instruction);
    }

    // ---- Feature capability: MẶC ĐỊNH TẮT (2a chỉ nối dây, chưa đổi hành vi) ----

    private function selectWithFeatures(LlmClient $llm, bool $enabled, bool $withActions = true): \App\Video\Director\ActionSelection
    {
        return (new ClaudeDirector($llm, 'haiku', $enabled))->select(
            $this->candidates(withFeatures: true, withActions: $withActions),
            $this->world(),
            null,
        );
    }

    public function test_the_whole_selection_is_unchanged_when_features_are_off(): void
    {
        // Cam kết của 2a: cùng input + cùng response cũ ⇒ RenderPlan y hệt
        // trước. Chốt cả object, không chỉ riêng featureCandidateIndices.
        $response = json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'secondary_indices' => [],
            'emotion' => 'wonder',
            'reveal' => 'immediate',
            'composition_note' => 'the hull fills the frame',
            'new_information' => 'the vessel is shown at full scale',
        ]);

        $selection = $this->select($this->llm($response));

        $this->assertSame('yacht', $selection->heroEntity);
        $this->assertSame(0, $selection->primaryCandidateIndex);
        $this->assertSame([], $selection->secondaryCandidateIndices);
        $this->assertSame('wonder', $selection->emotion);
        $this->assertSame('immediate', $selection->reveal);
        $this->assertSame('the hull fills the frame', $selection->compositionNote);
        $this->assertSame('the vessel is shown at full scale', $selection->newInformation);
        $this->assertSame([], $selection->featureCandidateIndices);

        // resolve() cũng phải cho ra đúng shape cũ — không key `features`.
        $resolved = $selection->resolve($this->candidates(withFeatures: true)['action_candidates']);
        $this->assertSame(['hero', 'primary', 'secondary'], array_keys($resolved));
    }

    public function test_features_are_off_by_default(): void
    {
        // Pipeline ĐÃ truyền feature_candidates cho Director từ trước. Nếu
        // parse() đọc feature_indices vô điều kiện thì Claude tự trả field đó
        // là feature lọt thẳng vào RenderPlan — bật hành vi mà không ai chủ ý.
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'feature_indices' => [0, 1],
        ]));

        $selection = $this->select($llm);

        $this->assertSame([], $selection->featureCandidateIndices);
    }

    public function test_the_prompt_hides_features_when_the_capability_is_off(): void
    {
        $llm = $this->llm('{}');
        $this->selectWithFeatures($llm, enabled: false);

        $this->assertStringNotContainsString('FEATURE CANDIDATES', $llm->lastRequest->input);
        $this->assertStringNotContainsString('infinity pool', $llm->lastRequest->input);
    }

    public function test_the_prompt_lists_features_when_the_capability_is_on(): void
    {
        $llm = $this->llm('{}');
        $this->selectWithFeatures($llm, enabled: true);

        $this->assertStringContainsString('FEATURE CANDIDATES', $llm->lastRequest->input);
        $this->assertStringContainsString('[0] Amadea: pool_feature = infinity pool', $llm->lastRequest->input);
        $this->assertStringContainsString('[1] Amadea: amenity = spa, helipad', $llm->lastRequest->input);
    }

    public function test_selected_features_are_read_when_the_capability_is_on(): void
    {
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'feature_indices' => [1],
        ]));

        $this->assertSame([1], $this->selectWithFeatures($llm, enabled: true)->featureCandidateIndices);
    }

    public function test_an_index_outside_the_supplied_list_is_dropped(): void
    {
        // Index bịa mà đi tiếp thì resolve() chạm vào null.
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'feature_indices' => [0, 99, -1, 'x'],
        ]));

        $this->assertSame([0], $this->selectWithFeatures($llm, enabled: true)->featureCandidateIndices);
    }

    public function test_a_repeated_feature_index_is_counted_once(): void
    {
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => 0,
            'feature_indices' => [1, 1, 1],
        ]));

        $this->assertSame([1], $this->selectWithFeatures($llm, enabled: true)->featureCandidateIndices);
    }

    public function test_a_scene_with_no_action_but_a_feature_has_a_null_primary(): void
    {
        // Đây là hình dạng 3/5 session ISA đã đo: hero giàu thuộc tính, 0 event.
        $llm = $this->llm(json_encode([
            'hero' => 'yacht',
            'primary_index' => null,
            'feature_indices' => [0],
        ]));

        $selection = $this->selectWithFeatures($llm, enabled: true, withActions: false);

        $this->assertNull($selection->primaryCandidateIndex);
        $this->assertSame([0], $selection->featureCandidateIndices);
    }

    public function test_a_bad_primary_index_still_falls_back_to_zero_when_actions_exist(): void
    {
        $llm = $this->llm(json_encode(['hero' => 'yacht', 'primary_index' => 99]));

        $this->assertSame(0, $this->selectWithFeatures($llm, enabled: true)->primaryCandidateIndex);
    }

    public function test_the_instruction_only_promises_what_the_prompt_supplies(): void
    {
        // Pipeline chỉ đưa 3 scene gần nhất, nên không đòi khác "every prior
        // scene" được — scene thứ 5 không có cách nào tuân.
        $llm = $this->llm('{}');
        $this->select($llm);

        $this->assertStringNotContainsString('every prior scene', $llm->lastRequest->instruction);
        $this->assertStringContainsString('every supplied previous scene', $llm->lastRequest->instruction);
    }
}
