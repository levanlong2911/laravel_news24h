<?php

namespace Tests\Feature\Video;

use App\Video\Screenplay\ScreenplayValidator;
use Tests\TestCase;

class ScreenplayExpansionContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode(
            (string) file_get_contents(resource_path($path)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function expansion(): array
    {
        $screenplay = $this->readJson('ai/screenplay/v3/04_worked_example.json');

        return array_intersect_key($screenplay, array_flip([
            'characters', 'locations', 'scenes', 'coverage',
        ]));
    }

    /** @return array<string, mixed> */
    private function sceneProfile(string $contract): array
    {
        $profile = $this->readJson('ai/screenplay/v3/04_worked_example.input.json')['profile'];
        $profile['contract_version'] = $contract;

        return $profile;
    }

    public function test_the_scene_expansion_accepts_only_the_validated_scene_sections(): void
    {
        $validator = new ScreenplayValidator;
        $profile = $this->sceneProfile('screenplay_scene_expansion_v1');
        $expansion = $this->expansion();

        $this->assertSame([], $validator->profileViolations($profile, 'screenplay_scene_expansion_v1'));
        $this->assertSame([], $validator->structural(
            $expansion,
            $profile,
            'screenplay_scene_expansion_v1',
        ));

        $expansion['logline'] = 'The expansion must not be allowed to rewrite the foundation.';

        $this->assertContains(
            'logline: this step does not produce it',
            $validator->structural($expansion, $profile, 'screenplay_scene_expansion_v1'),
        );
    }

    public function test_the_scene_expansion_keeps_coverage_and_build_state_rules_enabled(): void
    {
        $validator = new ScreenplayValidator;
        $profile = $this->sceneProfile('screenplay_scene_expansion_v1');
        $expansion = $this->expansion();
        array_pop($expansion['coverage']);
        $expansion['scenes'][0]['build_state'] = [
            'subject_id' => 'ch_unknown',
            'state' => 'A structure is present.',
        ];
        $violations = $validator->structural($expansion, $profile, 'screenplay_scene_expansion_v1');

        $this->assertTrue(
            collect($violations)->contains(
                fn (string $violation): bool => str_contains($violation, 'absent from the screenplay'),
            ),
            implode("\n", $violations),
        );
        $this->assertContains(
            'sc_01.build_state.subject_id: must name a declared character whose kind is object',
            $violations,
        );
    }

    public function test_v4_validates_the_unchanged_foundation_and_the_scene_expansion_together(): void
    {
        $validator = new ScreenplayValidator;
        $foundationInput = $this->readJson('ai/screenplay/foundation_v2/04_worked_example.input.json');
        $profile = $this->sceneProfile('screenplay_v4');
        $profile['dimension_bounds'] = $foundationInput['profile']['dimension_bounds'];
        $screenplay = $this->readJson('ai/screenplay/foundation_v2/04_worked_example.json') + $this->expansion();

        $this->assertSame([], $validator->profileViolations($profile, 'screenplay_v4'));
        $this->assertSame([], $validator->structural($screenplay, $profile, 'screenplay_v4'));

        $screenplay['principal_dimensions']['length_m'] = 10;

        $this->assertContains(
            'principal_dimensions.length_m: 10 is outside 30–60',
            $validator->structural($screenplay, $profile, 'screenplay_v4'),
        );
    }
}
