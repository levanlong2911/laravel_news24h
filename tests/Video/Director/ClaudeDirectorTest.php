<?php

namespace Tests\Video\Director;

use App\Video\Director\ClaudeDirector;
use App\Video\Editorial\ActionCandidate;
use App\Video\Editorial\ActionType;
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

    /** @return array{hero_candidates: list<string>, action_candidates: list<ActionCandidate>} */
    private function candidates(): array
    {
        return [
            'hero_candidates' => ['yacht'],
            'action_candidates' => [new ActionCandidate(ActionType::Lift, 'yacht')],
        ];
    }

    private function select(LlmClient $llm, array $priorScenes = []): \App\Video\Director\ActionSelection
    {
        return (new ClaudeDirector($llm))->select(
            $this->candidates(),
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
