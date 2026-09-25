<?php

namespace Tests\Feature\Video;

use App\Video\Screenplay\ScreenplayValidator;
use Tests\TestCase;

class ScreenplayV3ContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function screenplay(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/screenplay/v3_screenplay.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/profiles/screenplay/yacht_v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function structural(array $screenplay, array $excludedNames = []): array
    {
        return (new ScreenplayValidator)->structural(
            $screenplay,
            $this->profile(),
            'screenplay_v3',
            $excludedNames,
        );
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     * @return list<string>
     */
    private function violationsAfter(\Closure $mutate): array
    {
        return $this->structural($mutate($this->screenplay()));
    }

    /** @return array<string, mixed> */
    private function asV2(): array
    {
        $screenplay = $this->screenplay();

        foreach ($screenplay['characters'] as $index => $character) {
            if ($character['kind'] === 'group') {
                $screenplay['characters'][$index]['kind'] = 'person';
            }
        }

        return $screenplay;
    }

    public function test_a_group_is_a_v3_kind_and_is_refused_under_v2(): void
    {
        $profile = ['contract_version' => 'screenplay_v2'] + $this->profile();
        $violations = (new ScreenplayValidator)->structural($this->screenplay(), $profile, 'screenplay_v2');

        $this->assertNotSame([], $violations);

        foreach ($violations as $violation) {
            $this->assertStringContainsString('kind: must be one of object, person', $violation);
        }
    }

    public function test_the_v3_fixture_satisfies_every_rule_the_contract_carries(): void
    {
        $this->assertSame([], $this->structural($this->screenplay(), ['Vale Engineering']));
    }

    public function test_setting_a_coverage_item_aside_is_a_review_warning_not_a_refusal(): void
    {
        $warnings = (new ScreenplayValidator)->editorial($this->screenplay(), $this->profile());

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('cov_fin_snag', implode(' ', $warnings));
        $this->assertStringContainsString('cov_comp_trial', implode(' ', $warnings));
        $this->assertStringContainsString('needs an editor to accept', implode(' ', $warnings));
    }

    public function test_a_v2_screenplay_is_not_judged_by_v3_rules_and_the_reverse(): void
    {
        $validator = new ScreenplayValidator;
        $v2Profile = ['contract_version' => 'screenplay_v2'] + $this->profile();

        $this->assertSame(
            ['contract_version: profile does not match the author contract'],
            $validator->structural($this->screenplay(), $v2Profile, 'screenplay_v3'),
        );

        $this->assertSame(
            ['contract_version: unsupported author contract'],
            $validator->structural($this->screenplay(), $this->profile(), 'screenplay_v4'),
        );
    }

    public function test_the_version_the_server_passes_decides_the_rules_not_the_profile(): void
    {
        $screenplay = $this->asV2();
        unset($screenplay['coverage']);
        $profile = ['contract_version' => 'screenplay_v2'] + $this->profile();

        $this->assertSame(
            [],
            (new ScreenplayValidator)->structural($screenplay, $profile, 'screenplay_v2'),
        );

        $this->assertNotSame(
            [],
            (new ScreenplayValidator)->structural($screenplay, $this->profile(), 'screenplay_v3'),
        );
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider shapeFaults
     */
    public function test_output_of_the_wrong_type_returns_a_path_not_an_exception(\Closure $mutate, string $expected): void
    {
        $violations = $this->violationsAfter($mutate);

        $this->assertNotSame([], $violations);
        $this->assertSame([$expected], $violations);
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function shapeFaults(): array
    {
        return [
            'characters is a string' => [
                static function (array $s): array {
                    $s['characters'] = 'bad';

                    return $s;
                },
                'characters: must be a list',
            ],
            'locations is a string' => [
                static function (array $s): array {
                    $s['locations'] = 'bad';

                    return $s;
                },
                'locations: must be a list',
            ],
            'scenes is a string' => [
                static function (array $s): array {
                    $s['scenes'] = 'bad';

                    return $s;
                },
                'scenes: must be a list',
            ],
            'a scene is a string' => [
                static function (array $s): array {
                    $s['scenes'][0] = 'bad';

                    return $s;
                },
                'scenes[0]: must be an object',
            ],
            'character_ids is a string' => [
                static function (array $s): array {
                    $s['scenes'][0]['character_ids'] = 'bad';

                    return $s;
                },
                'sc_01.character_ids: must be a list',
            ],
            'dialogue is a string' => [
                static function (array $s): array {
                    $s['scenes'][0]['dialogue'] = 'bad';

                    return $s;
                },
                'sc_01.dialogue: must be a list',
            ],
            'logline is an array' => [
                static function (array $s): array {
                    $s['logline'] = ['bad'];

                    return $s;
                },
                'logline: must be a string',
            ],
            'design_thesis is a string' => [
                static function (array $s): array {
                    $s['design_thesis'] = 'bad';

                    return $s;
                },
                'design_thesis: must be an object',
            ],
            'premise is a string' => [
                static function (array $s): array {
                    $s['premise'] = 'bad';

                    return $s;
                },
                'premise: must be an object',
            ],
            'a character is a string' => [
                static function (array $s): array {
                    $s['characters'][0] = 'bad';

                    return $s;
                },
                'characters[0]: must be an object',
            ],
        ];
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider fieldTypeFaults
     */
    public function test_a_field_of_the_wrong_type_returns_its_own_path(\Closure $mutate, string $expected): void
    {
        $this->assertSame([$expected], $this->violationsAfter($mutate));
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function fieldTypeFaults(): array
    {
        $set = static fn (string $collection, int $index, string $field, mixed $value): \Closure
            => static function (array $s) use ($collection, $index, $field, $value): array {
                $s[$collection][$index][$field] = $value;

                return $s;
            };

        $drop = static fn (string $collection, int $index, string $field): \Closure
            => static function (array $s) use ($collection, $index, $field): array {
                unset($s[$collection][$index][$field]);

                return $s;
            };

        return [
            'character role is an array' => [$set('characters', 0, 'role', []), 'characters[0].role: must be a string'],
            'character id is an array' => [$set('characters', 0, 'id', []), 'characters[0].id: must be a string'],
            'character kind is an array' => [$set('characters', 0, 'kind', []), 'characters[0].kind: must be a string'],
            'character name is a number' => [$set('characters', 0, 'name', 7), 'characters[0].name: must be a string'],
            'location id is an array' => [$set('locations', 0, 'id', []), 'locations[0].id: must be a string'],
            'scene stage is an array' => [$set('scenes', 0, 'stage', []), 'sc_01.stage: must be a string'],
            'scene action is an array' => [$set('scenes', 0, 'action', []), 'sc_01.action: must be a string'],
            'scene id is an array' => [$set('scenes', 0, 'id', []), 'scenes[0].id: must be a string'],
            'duration is a numeric string' => [
                $set('scenes', 0, 'duration_estimate_ms', '12000'),
                'sc_01.duration_estimate_ms: must be an integer',
            ],
            'duration is a float' => [
                $set('scenes', 0, 'duration_estimate_ms', 12000.5),
                'sc_01.duration_estimate_ms: must be an integer',
            ],
            'duration is a boolean' => [
                $set('scenes', 0, 'duration_estimate_ms', true),
                'sc_01.duration_estimate_ms: must be an integer',
            ],
            'a character id in a scene is a number' => [
                static function (array $s): array {
                    $s['scenes'][0]['character_ids'][0] = 7;

                    return $s;
                },
                'sc_01.character_ids[0]: must be a string',
            ],
            'a dialogue line is a number' => [
                static function (array $s): array {
                    $s['scenes'][1]['dialogue'][0]['line'] = 7;

                    return $s;
                },
                'sc_02.dialogue[0].line: must be a string',
            ],
            'a dialogue speaker is missing' => [
                static function (array $s): array {
                    unset($s['scenes'][1]['dialogue'][0]['character_id']);

                    return $s;
                },
                'sc_02.dialogue[0].character_id: is required',
            ],
            'design thesis field is an array' => [
                static function (array $s): array {
                    $s['design_thesis']['central_idea'] = [];

                    return $s;
                },
                'design_thesis.central_idea: must be a string',
            ],
            'premise field is missing' => [
                static function (array $s): array {
                    unset($s['premise']['answer']);

                    return $s;
                },
                'premise.answer: is required',
            ],
            'required field is missing' => [$drop('scenes', 0, 'action'), 'sc_01.action: is required'],
            'required appearance is missing' => [$drop('characters', 0, 'appearance'), 'characters[0].appearance: is required'],
            'required kind is missing' => [$drop('characters', 0, 'kind'), 'characters[0].kind: is required'],
            'a nullable field is missing entirely' => [$drop('characters', 0, 'personality'), 'characters[0].personality: is required'],
            'the last scene omits leads_to' => [$drop('scenes', 7, 'leads_to'), 'sc_08.leads_to: is required'],
            'sound is missing' => [$drop('scenes', 0, 'sound'), 'sc_01.sound: is required'],
            'a field that may not be null is null' => [$set('scenes', 0, 'action', null), 'sc_01.action: must not be null'],
            'a character id is null' => [$set('characters', 0, 'id', null), 'characters[0].id: must not be null'],
            'an unsupported role' => [
                $set('characters', 1, 'role', 'narrator'),
                'characters[1].role: must be one of protagonist, supporting, incidental',
            ],
            'an unsupported kind' => [
                $set('characters', 0, 'kind', 'vessel'),
                'characters[0].kind: must be one of object, person, group',
            ],
            'an unsupported int_ext' => [$set('scenes', 0, 'int_ext', 'INTERIOR'), 'sc_01.int_ext: must be one of INT, EXT'],
            'an unsupported time' => [
                $set('scenes', 0, 'time', 'MIDDAY'),
                'sc_01.time: must be one of DAY, NIGHT, DAWN, DUSK, CONTINUOUS',
            ],
        ];
    }

    public function test_a_nullable_field_may_carry_null(): void
    {
        $this->assertSame([], $this->violationsAfter(static function (array $s): array {
            $s['characters'][1]['personality'] = null;
            $s['scenes'][0]['sound'] = null;

            return $s;
        }));
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider coverageFaults
     */
    public function test_a_coverage_fault_is_structural(\Closure $mutate, string $expected): void
    {
        $this->assertStringContainsString($expected, implode(' | ', $this->violationsAfter($mutate)));
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function coverageFaults(): array
    {
        return [
            'coverage absent' => [
                static function (array $s): array {
                    unset($s['coverage']);

                    return $s;
                },
                'coverage: must be a list',
            ],
            'coverage is an object' => [
                static function (array $s): array {
                    $s['coverage'] = ['cov_op_guests' => 'shown'];

                    return $s;
                },
                'coverage: must be a list',
            ],
            'an item is a string' => [
                static function (array $s): array {
                    $s['coverage'][0] = 'cov_design_brief';

                    return $s;
                },
                'coverage[0]: must be an object',
            ],
            'an item the profile never declared' => [
                static function (array $s): array {
                    $s['coverage'][0]['coverage_id'] = 'cov_invented_item';

                    return $s;
                },
                'coverage[0].coverage_id: is not declared by the profile',
            ],
            'the same item twice' => [
                static function (array $s): array {
                    $s['coverage'][1]['coverage_id'] = $s['coverage'][0]['coverage_id'];

                    return $s;
                },
                'appears more than once',
            ],
            'a declared item left out' => [
                static function (array $s): array {
                    array_splice($s['coverage'], 0, 1);

                    return $s;
                },
                'cov_design_brief is declared by the profile but absent',
            ],
            'a required item marked transition' => [
                static function (array $s): array {
                    $s['coverage'][1]['mode'] = 'transition';
                    $s['coverage'][1]['scene_ids'] = ['sc_01', 'sc_02'];

                    return $s;
                },
                'is required and accepts only shown',
            ],
            'a required item set aside' => [
                static function (array $s): array {
                    $s['coverage'][1]['mode'] = 'not_applicable';
                    $s['coverage'][1]['scene_ids'] = [];

                    return $s;
                },
                'is required and accepts only shown',
            ],
            'an unsupported mode' => [
                static function (array $s): array {
                    $s['coverage'][0]['mode'] = 'partly';

                    return $s;
                },
                'unsupported coverage mode',
            ],
            'shown without a scene' => [
                static function (array $s): array {
                    $s['coverage'][0]['scene_ids'] = [];

                    return $s;
                },
                'shown needs at least one scene',
            ],
            'transition with one scene' => [
                static function (array $s): array {
                    $s['coverage'][3]['scene_ids'] = ['sc_02'];

                    return $s;
                },
                'transition needs exactly two scenes, carries 1',
            ],
            'transition with three scenes' => [
                static function (array $s): array {
                    $s['coverage'][3]['scene_ids'] = ['sc_01', 'sc_02', 'sc_03'];

                    return $s;
                },
                'transition needs exactly two scenes, carries 3',
            ],
            'transition in reverse order' => [
                static function (array $s): array {
                    $s['coverage'][3]['scene_ids'] = ['sc_03', 'sc_02'];

                    return $s;
                },
                'in screenplay order',
            ],
            'transition naming one scene twice' => [
                static function (array $s): array {
                    $s['coverage'][3]['scene_ids'] = ['sc_02', 'sc_02'];

                    return $s;
                },
                'names the same scene twice',
            ],
            'not_applicable carrying a scene' => [
                static function (array $s): array {
                    $s['coverage'][18]['scene_ids'] = ['sc_05'];

                    return $s;
                },
                'not_applicable must name no scene',
            ],
            'a scene that was never declared' => [
                static function (array $s): array {
                    $s['coverage'][0]['scene_ids'] = ['sc_99'];

                    return $s;
                },
                'coverage[0].scene_ids[0]: names a scene that is not declared',
            ],
            'scene_ids is a string' => [
                static function (array $s): array {
                    $s['coverage'][0]['scene_ids'] = 'sc_01';

                    return $s;
                },
                'coverage[0].scene_ids: must be a list',
            ],
            'scene_ids holds a number' => [
                static function (array $s): array {
                    $s['coverage'][0]['scene_ids'] = [1];

                    return $s;
                },
                'coverage[0].scene_ids[0]: names a scene that is not declared',
            ],
            'evidence is only whitespace' => [
                static function (array $s): array {
                    $s['coverage'][0]['evidence'] = "   \n\t ";

                    return $s;
                },
                'coverage[0].evidence: must be a nonempty string',
            ],
            'evidence is absent' => [
                static function (array $s): array {
                    unset($s['coverage'][0]['evidence']);

                    return $s;
                },
                'coverage[0].evidence: must be a nonempty string',
            ],
            'evidence is a number' => [
                static function (array $s): array {
                    $s['coverage'][0]['evidence'] = 12;

                    return $s;
                },
                'coverage[0].evidence: must be a nonempty string',
            ],
        ];
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider buildStateFaults
     */
    public function test_a_build_state_fault_is_structural(\Closure $mutate, string $expected): void
    {
        $this->assertStringContainsString($expected, implode(' | ', $this->violationsAfter($mutate)));
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function buildStateFaults(): array
    {
        return [
            'the field is absent' => [
                static function (array $s): array {
                    unset($s['scenes'][0]['build_state']);

                    return $s;
                },
                'sc_01.build_state: must be present',
            ],
            'the field is a string' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state'] = 'frames standing';

                    return $s;
                },
                'sc_01.build_state: must be an object or null',
            ],
            'the subject is a person' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state']['subject_id'] = 'ch_lead';

                    return $s;
                },
                'sc_01.build_state.subject_id: must name a declared character whose kind is object',
            ],
            'the subject is a group' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state']['subject_id'] = 'ch_design_team';

                    return $s;
                },
                'sc_01.build_state.subject_id: must name a declared character whose kind is object',
            ],
            'the subject was never declared' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state']['subject_id'] = 'ch_ghost';

                    return $s;
                },
                'sc_01.build_state.subject_id: must name a declared character whose kind is object',
            ],
            'the subject is absent' => [
                static function (array $s): array {
                    unset($s['scenes'][0]['build_state']['subject_id']);

                    return $s;
                },
                'sc_01.build_state.subject_id: must name a declared character whose kind is object',
            ],
            'the state is only whitespace' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state']['state'] = "  \t\n ";

                    return $s;
                },
                'sc_01.build_state.state: must be a nonempty string',
            ],
            'the state is absent' => [
                static function (array $s): array {
                    unset($s['scenes'][0]['build_state']['state']);

                    return $s;
                },
                'sc_01.build_state.state: must be a nonempty string',
            ],
            'the state is a number' => [
                static function (array $s): array {
                    $s['scenes'][0]['build_state']['state'] = 3;

                    return $s;
                },
                'sc_01.build_state.state: must be a nonempty string',
            ],
        ];
    }

    public function test_a_scene_where_build_state_does_not_apply_may_carry_null(): void
    {
        $this->assertSame([], $this->violationsAfter(static function (array $s): array {
            $s['scenes'][0]['build_state'] = null;

            return $s;
        }));
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider speakerFaults
     */
    public function test_a_speaker_fault_is_structural(\Closure $mutate, string $expected): void
    {
        $this->assertStringContainsString($expected, implode(' | ', $this->violationsAfter($mutate)));
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function speakerFaults(): array
    {
        return [
            'a declared person who is not in the scene' => [
                static function (array $s): array {
                    $s['scenes'][1]['dialogue'][0]['character_id'] = 'ch_captain';

                    return $s;
                },
                'ch_captain speaks but is not among the scene character_ids',
            ],
            'a group given a line' => [
                static function (array $s): array {
                    $s['scenes'][1]['dialogue'][0]['character_id'] = 'ch_design_team';

                    return $s;
                },
                'ch_design_team is not a person and cannot speak',
            ],
            'the object given a line' => [
                static function (array $s): array {
                    $s['scenes'][1]['character_ids'][] = 'ch_vessel';
                    $s['scenes'][1]['dialogue'][0]['character_id'] = 'ch_vessel';

                    return $s;
                },
                'ch_vessel is not a person and cannot speak',
            ],
            'a line that is not an object' => [
                static function (array $s): array {
                    $s['scenes'][1]['dialogue'][0] = 'If the run breaks here, it is only a drawing.';

                    return $s;
                },
                'sc_02.dialogue[0]: must be an object',
            ],
        ];
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     *
     * @dataProvider limitFaults
     */
    public function test_a_limit_fault_is_structural(\Closure $mutate, string $expected): void
    {
        $this->assertStringContainsString($expected, implode(' | ', $this->violationsAfter($mutate)));
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function limitFaults(): array
    {
        return [
            'one subject too many' => [
                static function (array $s): array {
                    for ($i = 0; $i < 3; $i++) {
                        $extra = $s['characters'][1];
                        $extra['id'] = 'ch_extra_'.$i;
                        $s['characters'][] = $extra;
                    }

                    return $s;
                },
                'characters declares 13 entries, profile max_subjects allows 12',
            ],
            'one location too many' => [
                static function (array $s): array {
                    for ($i = 0; $i < 9; $i++) {
                        $extra = $s['locations'][0];
                        $extra['id'] = 'lo_extra_'.$i;
                        $s['locations'][] = $extra;
                    }

                    return $s;
                },
                'locations declares 13 entries, profile max_locations allows 12',
            ],
            'characters is an object' => [
                static function (array $s): array {
                    $s['characters'] = ['ch_vessel' => $s['characters'][0]];

                    return $s;
                },
                'characters: must be a list',
            ],
        ];
    }

    /**
     * @param  \Closure(array<string, mixed>, string): array<string, mixed>  $mutate
     *
     * @dataProvider newNarrativePaths
     */
    public function test_source_facts_are_refused_on_the_paths_v3_adds(\Closure $mutate, string $path): void
    {
        foreach ([
            'The deck runs 180 metres between the two towers.' => "{$path} carries a measurement",
            'The hull was closed in March 2024 at the yard.' => "{$path} carries a calendar date",
            'Vale Engineering closed the hull along its length.' => "{$path} carries the excluded name Vale Engineering",
        ] as $text => $expected) {
            $violations = $this->structural(
                $mutate($this->screenplay(), $text),
                ['Vale Engineering'],
            );

            $this->assertStringContainsString($expected, implode(' | ', $violations), $text);
        }
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function newNarrativePaths(): array
    {
        return [
            'build_state.state' => [
                static function (array $s, string $text): array {
                    $s['scenes'][0]['build_state']['state'] = $text;

                    return $s;
                },
                'sc_01.build_state.state',
            ],
            'coverage evidence' => [
                static function (array $s, string $text): array {
                    $s['coverage'][0]['evidence'] = $text;

                    return $s;
                },
                'coverage[0].evidence',
            ],
        ];
    }

    public function test_the_paths_v3_adds_are_not_scanned_when_the_contract_is_v2(): void
    {
        $screenplay = $this->asV2();
        $screenplay['scenes'][0]['build_state']['state'] = 'The deck runs 180 metres between the two towers.';
        $screenplay['coverage'][0]['evidence'] = 'The hull was closed in March 2024 at the yard.';
        $profile = ['contract_version' => 'screenplay_v2'] + $this->profile();

        $this->assertSame(
            [],
            (new ScreenplayValidator)->structural($screenplay, $profile, 'screenplay_v2'),
        );
    }
}
