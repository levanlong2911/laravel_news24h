<?php

namespace Tests\Feature\Video;

use App\Video\Screenplay\CreativeInspirationBuilder;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayText;
use App\Video\Screenplay\ScreenplayValidator;
use Tests\TestCase;

class ScreenplayContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function example(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/v2/04_worked_example.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function exampleInput(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/v2/04_worked_example.input.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $excludedNames
     * @return list<string>
     */
    private function structural(array $screenplay, array $profile, array $excludedNames = []): array
    {
        return (new ScreenplayValidator)->structural(
            $screenplay,
            $profile + ['contract_version' => 'screenplay_v2'],
            'screenplay_v2',
            $excludedNames,
        );
    }

    public function test_legacy_example_validation_does_not_require_version_metadata_in_the_prompt(): void
    {
        $input = $this->exampleInput();

        $this->assertArrayNotHasKey('contract_version', $input['profile']);
        $this->assertSame([], $this->structural($this->example(), $input['profile']));
        $this->assertArrayNotHasKey('contract_version', $this->exampleInput()['profile']);
    }

    public function test_the_v2_test_adapter_does_not_override_an_explicit_contract_mismatch(): void
    {
        $profile = $this->exampleInput()['profile'];
        $profile['contract_version'] = 'screenplay_v3';

        $this->assertSame(
            ['contract_version: profile does not match the author contract'],
            $this->structural($this->example(), $profile),
        );
    }

    public function test_the_worked_example_satisfies_the_contract_it_teaches(): void
    {
        $input = $this->exampleInput();
        $validator = new ScreenplayValidator;

        $this->assertSame(
            [],
            $this->structural($this->example(), $input['profile'], ['Vale Engineering']),
        );

        $this->assertSame([], $validator->editorial($this->example(), $input['profile']));
    }

    public function test_the_builder_keeps_the_ideas_and_drops_the_quotes(): void
    {
        [$inspiration, $violations] = (new CreativeInspirationBuilder)->build([
            'article_focus' => 'An 82-metre vessel built by Vale Engineering.',
            'excluded_context' => [['type' => 'contractor', 'value' => 'Vale Engineering']],
            'source_insights' => [[
                'aspect' => 'size',
                'summary' => 'The deck runs 180 metres between towers.',
                'source_quotes' => ['Vale Engineering spun the cables in 2024.'],
            ]],
        ]);

        $this->assertSame([], $violations);
        $this->assertSame(['ideas'], array_keys($inspiration));
        $this->assertSame('The deck runs 180 metres between towers.', $inspiration['ideas'][0]['idea']);

        $encoded = json_encode($inspiration);
        $this->assertStringNotContainsString('Vale Engineering', $encoded);
        $this->assertStringNotContainsString('2024', $encoded);
    }

    /** @return array<string, mixed> */
    private function withLogline(string $logline): array
    {
        $screenplay = $this->example();
        $screenplay['logline'] = $logline;

        return $screenplay;
    }

    public function test_a_measurement_in_the_output_is_refused_in_digits_and_in_words(): void
    {
        $profile = $this->exampleInput()['profile'];

        foreach ([
            'The deck runs 180 metres between two towers over the river.',
            'Eighty-two metres long, and twelve point eight metres in the beam.',
            'A range of six thousand two hundred nautical miles at fourteen knots.',
        ] as $logline) {
            $this->assertStringContainsString(
                'carries a measurement',
                implode(' ', $this->structural($this->withLogline($logline), $profile)),
                $logline,
            );
        }
    }

    public function test_ordinary_counting_and_times_of_day_are_left_alone(): void
    {
        $profile = $this->exampleInput()['profile'];

        foreach ([
            'Two riggers work the near rim with a pair of cables between them.',
            'The work runs from dawn until dusk and begins again at first light.',
        ] as $logline) {
            $this->assertSame(
                [],
                $this->structural($this->withLogline($logline), $profile),
                $logline,
            );
        }
    }

    public function test_a_calendar_date_and_an_excluded_name_are_refused_in_the_output(): void
    {
        $profile = $this->exampleInput()['profile'];

        $this->assertStringContainsString(
            'calendar date',
            implode(' ', $this->structural(
                $this->withLogline('The towers went up in March 2024 above the river.'),
                $profile,
            )),
        );

        $this->assertStringContainsString(
            'Vale Engineering',
            implode(' ', $this->structural(
                $this->withLogline('Vale Engineering raised the towers above the river.'),
                $profile,
                ['Vale Engineering'],
            )),
        );
    }

    public function test_a_broken_link_and_a_duplicate_id_are_structural(): void
    {
        $screenplay = $this->example();
        $screenplay['scenes'][0]['location_id'] = 'lo_nowhere';
        $screenplay['characters'][1]['id'] = $screenplay['characters'][0]['id'];

        $violations = $this->structural($screenplay, $this->exampleInput()['profile']);

        $this->assertNotSame([], $violations);
        $this->assertStringContainsString('lo_nowhere', implode(' ', $violations));
        $this->assertStringContainsString('duplicate ids', implode(' ', $violations));
    }

    public function test_a_missing_required_stage_is_structural(): void
    {
        $screenplay = $this->example();
        $screenplay['scenes'] = array_values(array_filter(
            $screenplay['scenes'],
            static fn (array $scene): bool => $scene['stage'] !== 'operation',
        ));

        $violations = $this->structural($screenplay, $this->exampleInput()['profile']);

        $this->assertStringContainsString('required stage operation', implode(' ', $violations));
    }

    public function test_a_stage_that_steps_backwards_is_structural(): void
    {
        $screenplay = $this->example();
        $profile = $this->exampleInput()['profile'];
        $target = null;

        foreach ($screenplay['scenes'] as $index => $scene) {
            $previous = $index > 0 ? $screenplay['scenes'][$index - 1]['stage'] : null;

            if ($previous !== null && array_search($previous, $profile['arc_stages'], true) > 0) {
                $target = $index;

                break;
            }
        }

        $this->assertNotNull($target, 'the example has no scene preceded by a stage after design');

        $screenplay['scenes'][$target]['stage'] = 'design';

        $violations = $this->structural($screenplay, $profile);

        $this->assertStringContainsString('steps back', implode(' ', $violations));
    }

    public function test_an_action_written_about_the_audience_is_an_editorial_warning(): void
    {
        $screenplay = $this->example();
        $screenplay['scenes'][0]['action'] = 'The interior layout is unseen until the camera arrives.';

        $warnings = (new ScreenplayValidator)->editorial($screenplay, $this->exampleInput()['profile']);

        $this->assertStringContainsString('describes the audience', implode(' ', $warnings));
    }

    public function test_the_fingerprint_changes_with_profile_duration_and_model(): void
    {
        $author = app(ScreenplayAuthor::class);
        $input = $this->exampleInput();

        $requirements = $input['film_requirements'];
        $base = $author->fingerprint($input['inspiration'], $input['profile'], $requirements);

        $wide = $requirements;
        $wide['aspect_ratio'] = '16:9';

        $this->assertNotSame($base, $author->fingerprint($input['inspiration'], $input['profile'], $wide));

        $other = $input['profile'];
        $other['max_scenes'] = 99;

        $this->assertNotSame($base, $author->fingerprint($input['inspiration'], $other, $requirements));
    }

    public function test_a_legacy_screenplay_still_renders_and_an_unknown_version_says_so(): void
    {
        $legacy = ScreenplayText::render([
            'logline' => 'An older screenplay with no version stamp.',
            'subjects' => [['id' => 'sub_a', 'role' => 'protagonist', 'identity' => 'A shape.']],
            'scenes' => [['id' => 'sc_01', 'beat' => 'hook', 'location_id' => 'l_a', 'purpose' => 'Open.']],
        ]);

        $this->assertStringContainsString('LEGACY SCREENPLAY', $legacy);
        $this->assertStringContainsString('sub_a', $legacy);

        $this->assertStringContainsString(
            'is not supported',
            ScreenplayText::render(['schema_version' => 'screenplay_v9']),
        );
    }

    public function test_a_stored_v2_screenplay_still_renders_when_the_author_moves_on(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(ScreenplayText::class))->getFileName(),
        );

        foreach (['SCHEMA_VERSION', 'DEFAULT_CONTRACT', 'ScreenplayAuthor', 'contract_version'] as $moving) {
            $this->assertStringNotContainsString(
                $moving,
                $source,
                'ScreenplayText must branch on literal version strings; binding it to the author'
                .' constant or to configuration makes every stored row unreadable the moment'
                .' the author moves on',
            );
        }

        $text = ScreenplayText::render($this->example() + ['schema_version' => 'screenplay_v2']);

        $this->assertStringContainsString('DESIGN THESIS', $text);
        $this->assertStringNotContainsString('is not supported', $text);
    }

    public function test_the_example_renders_as_readable_screenplay(): void
    {
        $screenplay = $this->example();

        $text = ScreenplayText::render($screenplay + ['schema_version' => 'screenplay_v2']);

        $first = $screenplay['scenes'][0];
        $location = '';

        foreach ($screenplay['locations'] as $row) {
            if ($row['id'] === $first['location_id']) {
                $location = (string) $row['name'];
            }
        }

        $this->assertStringContainsString('DESIGN THESIS', $text);
        $this->assertStringContainsString('PREMISE', $text);
        $this->assertStringContainsString(
            $first['int_ext'].'. '.mb_strtoupper($location).' — '.$first['time'],
            $text,
        );
        $this->assertStringContainsString('Cannot be cut', $text);
        $this->assertStringContainsString('Leads to', $text);
        $this->assertStringContainsString('Central idea', $text);
    }

    public function test_the_premise_carries_a_question_and_an_answer(): void
    {
        $premise = $this->example()['premise'];

        foreach (['question', 'force', 'device', 'change', 'answer'] as $key) {
            $this->assertArrayHasKey($key, $premise);
            $this->assertNotSame('', trim((string) $premise[$key]));
        }
    }

    public function test_leads_to_is_required_everywhere_but_the_last_scene(): void
    {
        $profile = $this->exampleInput()['profile'];

        $missing = $this->example();
        $missing['scenes'][1]['leads_to'] = null;

        $this->assertStringContainsString(
            'must say what it leads to',
            implode(' ', $this->structural($missing, $profile)),
        );

        $trailing = $this->example();
        $trailing['scenes'][array_key_last($trailing['scenes'])]['leads_to'] = 'The story continues.';

        $this->assertStringContainsString(
            'must carry a null leads_to',
            implode(' ', $this->structural($trailing, $profile)),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function narrativePaths(): array
    {
        return [
            'design_thesis.central_idea' => ['design_thesis.central_idea', 'design_thesis.central_idea'],
            'design_thesis.visible_difference' => ['design_thesis.visible_difference', 'design_thesis.visible_difference'],
            'design_thesis.spatial_consequence' => ['design_thesis.spatial_consequence', 'design_thesis.spatial_consequence'],
            'design_thesis.coherence' => ['design_thesis.coherence', 'design_thesis.coherence'],
            'design_thesis.realization' => ['design_thesis.realization', 'design_thesis.realization'],
            'premise.question' => ['premise.question', 'premise.question'],
            'premise.answer' => ['premise.answer', 'premise.answer'],
            'scenes[0].leads_to' => ['scenes[0].leads_to', 'sc_01.leads_to'],
        ];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return array<string, mixed>
     */
    private function writeNarrative(array $screenplay, string $path, string $value): array
    {
        if ($path === 'scenes[0].leads_to') {
            $screenplay['scenes'][0]['leads_to'] = $value;

            return $screenplay;
        }

        [$group, $field] = explode('.', $path, 2);
        $screenplay[$group][$field] = $value;

        return $screenplay;
    }

    /** @dataProvider narrativePaths */
    public function test_a_measurement_is_refused_in_every_narrative_path(string $path, string $key): void
    {
        $violations = $this->structural(
            $this->writeNarrative($this->example(), $path, 'The span reaches 82 metres from one rim to the other.'),
            $this->exampleInput()['profile'],
        );

        $this->assertContains("{$key} carries a measurement: 82 metres", $violations);
    }

    /** @dataProvider narrativePaths */
    public function test_a_calendar_date_is_refused_in_every_narrative_path(string $path, string $key): void
    {
        $violations = $this->structural(
            $this->writeNarrative($this->example(), $path, 'The towers went up in March 2024 above the river.'),
            $this->exampleInput()['profile'],
        );

        $this->assertContains("{$key} carries a calendar date: 2024", $violations);
    }

    /** @dataProvider narrativePaths */
    public function test_an_excluded_name_is_refused_in_every_narrative_path(string $path, string $key): void
    {
        $violations = $this->structural(
            $this->writeNarrative($this->example(), $path, 'Vale Engineering raised the towers above the river.'),
            $this->exampleInput()['profile'],
            ['Vale Engineering'],
        );

        $this->assertContains("{$key} carries the excluded name Vale Engineering", $violations);
    }

    public function test_the_new_narrative_paths_leave_clean_prose_alone(): void
    {
        $profile = $this->exampleInput()['profile'];

        foreach (array_keys(self::narrativePaths()) as $path) {
            $screenplay = $this->writeNarrative(
                $this->example(),
                $path,
                'Two riggers work the near rim from dawn until dusk and begin again at first light.',
            );

            $this->assertSame([], $this->structural($screenplay, $profile), $path);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function shippedScreenplayProfiles(): array
    {
        $found = glob(__DIR__.'/../../../resources/ai/profiles/screenplay/*.json') ?: [];

        return array_combine(
            array_map(static fn (string $p): string => basename($p, '.json'), $found),
            array_map(static fn (string $p): array => [$p], $found),
        );
    }

    /** @dataProvider shippedScreenplayProfiles */
    public function test_every_shipped_profile_declares_the_contract_it_was_written_for(string $path): void
    {
        $profile = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression(
            '/^screenplay_v[0-9]+$/',
            (string) ($profile['contract_version'] ?? ''),
            basename($path).' must declare contract_version; without it the service guard'
            .' cannot tell which ruleset the profile was written for',
        );
    }

    public function test_the_profile_in_use_matches_the_author_contract(): void
    {
        $key = config('video.screenplay.profiles.yacht');
        $path = rtrim((string) config('video.screenplay.profile_dir'), '/\\').DIRECTORY_SEPARATOR.$key.'.json';

        $profile = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            (string) config('video.screenplay.contract_version'),
            $profile['contract_version'] ?? null,
            'the configured profile and the author contract have drifted apart; the service'
            .' guard would refuse every screenplay run before it reached the model',
        );
    }

    /** @return array<string, mixed> */
    private function categoryProfile(): array
    {
        return $this->exampleInput()['profile'] + [
            'concept_forbidden_terms' => ['fin', 'wedding cake'],
            'concept_antipatterns' => [
                'the category default: a three-tier stepped superstructure set aft on a long foredeck, '
                    .'a raked stem, a reverse transom with full-beam swim platform, '
                    .'and continuous horizontal glazing bands',
            ],
        ];
    }

    public function test_wording_shared_with_an_antipattern_warns_but_does_not_block(): void
    {
        $screenplay = $this->example();
        $screenplay['design_thesis']['visible_difference'] =
            'Instead of a tiered superstructure set aft on a long foredeck, one continuous volume '
            .'sits on the hull. The stern carries a stepped transom rather than a full-beam platform, '
            .'and the glazing follows the sheer at three different heights.';

        $validator = new ScreenplayValidator;
        $profile = $this->categoryProfile();

        $this->assertSame([], $this->structural($screenplay, $profile));

        $this->assertStringContainsString(
            'shares wording with a category antipattern',
            implode(' ', $validator->editorial($screenplay, $profile)),
        );
    }

    public function test_a_forbidden_term_in_the_appearance_is_structural(): void
    {
        $screenplay = $this->example();
        $screenplay['characters'][0]['appearance'] = 'A hull with a tall fin standing above the deck.';

        $this->assertStringContainsString(
            'forbidden term "fin"',
            implode(' ', $this->structural($screenplay, $this->categoryProfile())),
        );
    }

    public function test_a_word_that_merely_contains_a_forbidden_term_is_left_alone(): void
    {
        $screenplay = $this->example();
        $screenplay['characters'][0]['appearance'] = 'A hull of finished steel with a defined sheer line.';

        $this->assertSame(
            [],
            $this->structural($screenplay, $this->categoryProfile()),
        );
    }

    public function test_month_words_in_ordinary_prose_are_not_calendar_dates(): void
    {
        $profile = $this->exampleInput()['profile'];

        foreach ([
            'The opening may remain uncovered until the deck reaches it.',
            'Workers march the last section out along the running wire.',
            'An august hall of welded steel stands over the berth.',
        ] as $logline) {
            $this->assertSame(
                [],
                $this->structural($this->withLogline($logline), $profile),
                $logline,
            );
        }

        foreach ([
            'The towers went up in March 2024 above the river.',
            'The keel was laid on March 12 and the hall closed after.',
            'The keel was laid on March 12th and the hall closed after.',
            'The keel was laid on 12 March and the hall closed after.',
            'The keel was laid on 12th March and the hall closed after.',
        ] as $logline) {
            $this->assertStringContainsString(
                'carries a calendar date',
                implode(' ', $this->structural($this->withLogline($logline), $profile)),
                $logline,
            );
        }
    }

    public function test_ids_and_durations_are_not_scanned_as_narrative_text(): void
    {
        $screenplay = $this->example();
        $screenplay['scenes'][0]['duration_estimate_ms'] = 2024;

        $this->assertSame(
            [],
            $this->structural(
                $screenplay,
                $this->exampleInput()['profile'],
                [
                    $screenplay['scenes'][0]['id'],
                    $screenplay['characters'][1]['id'],
                    $screenplay['locations'][0]['id'],
                ],
            ),
        );
    }
}
