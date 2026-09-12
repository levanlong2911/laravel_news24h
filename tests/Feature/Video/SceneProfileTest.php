<?php

namespace Tests\Feature\Video;

use App\Video\Scene\ScenePlanException;
use App\Video\Scene\SceneProfile;
use Tests\TestCase;

class SceneProfileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/scene_profiles_'.uniqid());

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_the_shipped_vessel_profile_matches_its_agreed_shape(): void
    {
        $profile = SceneProfile::load(
            (string) config('video.scene_plan.profile_dir'),
            'vessel_v1',
        );

        $this->assertCount(7, $profile->phaseKeys());
        $this->assertCount(20, $profile->milestoneKeys());
        $this->assertCount(15, $profile->requiredMilestoneKeys());
        $this->assertSame(10, $profile->minScenes);
        $this->assertSame(3, $profile->maxMilestonesPerScene);
        $this->assertSame('finishing', $profile->phaseOf('painted'));
        $this->assertSame('Launched', $profile->labelOf('launched'));
        $this->assertNull($profile->indexOf('nothing_like_this'));

        $this->assertLessThan(
            $profile->indexOf('painted'),
            $profile->indexOf('surface_faired'),
            'fairing must come before painting',
        );
    }

    public function test_two_profiles_loaded_in_a_row_do_not_share_their_milestones(): void
    {
        $this->write('other_v1', $this->body('other_v1', 'welding_done'));

        $vessel = SceneProfile::load((string) config('video.scene_plan.profile_dir'), 'vessel_v1');
        $other = SceneProfile::load($this->dir, 'other_v1');

        $this->assertContains('painted', $vessel->milestoneKeys());
        $this->assertNotContains('painted', $other->milestoneKeys());
        $this->assertSame(['welding_done'], $other->milestoneKeys());
        $this->assertNull($other->indexOf('painted'));
    }

    public function test_a_string_false_is_not_accepted_as_a_boolean(): void
    {
        $this->write('bad_bool_v1', str_replace(
            '"required": true',
            '"required": "false"',
            $this->body('bad_bool_v1', 'welding_done'),
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessage('must declare required as a boolean');

        SceneProfile::load($this->dir, 'bad_bool_v1');
    }

    public function test_a_milestone_key_may_not_repeat_across_groups(): void
    {
        $this->write('dup_v1', $this->twoGroups('dup_v1', 'same_key', 'same_key'));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessage('repeats across groups');

        SceneProfile::load($this->dir, 'dup_v1');
    }

    public function test_a_profile_below_the_scene_floor_is_refused(): void
    {
        $this->write('low_v1', str_replace(
            '"min_scenes": 10',
            '"min_scenes": 3',
            $this->body('low_v1', 'welding_done'),
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessage('min_scenes must be at least 10');

        SceneProfile::load($this->dir, 'low_v1');
    }

    public function test_a_file_naming_a_different_version_is_refused(): void
    {
        $this->write('renamed_v1', $this->body('original_v1', 'welding_done'));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessage('declares a different version');

        SceneProfile::load($this->dir, 'renamed_v1');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessage('not found');

        SceneProfile::load($this->dir, 'never_written_v1');
    }

    public function test_the_shipped_v2_profile_declares_an_environment_for_every_phase(): void
    {
        $profile = SceneProfile::load(
            resource_path('ai/profiles/scene_planning'), 'vessel_v2',
        );

        $this->assertSame(
            ['design_studio', 'shipyard_hall', 'paint_shed', 'launch_quay', 'open_water'],
            $profile->environmentKeys(),
        );
        $this->assertSame('Paint shed', $profile->environmentLabelOf('paint_shed'));
        $this->assertStringContainsString('sealed white walls', (string) $profile->environmentPromptOf('paint_shed'));
        $this->assertNull($profile->environmentLabelOf('dry_dock'));
    }

    public function test_the_v1_profile_still_loads_without_environments(): void
    {
        $profile = SceneProfile::load(
            resource_path('ai/profiles/scene_planning'), 'vessel_v1',
        );

        $this->assertSame([], $profile->environmentKeys());
    }

    public function test_the_two_shipped_vessel_profiles_stay_key_for_key_identical(): void
    {
        $dir = resource_path('ai/profiles/scene_planning');

        $v1 = SceneProfile::load($dir, 'vessel_v1');
        $v2 = SceneProfile::load($dir, 'vessel_v2');

        $this->assertSame(
            $v1->phaseKeys(), $v2->phaseKeys(),
            'scene plans made under vessel_v1 resolve their environment through vessel_v2',
        );
        $this->assertSame($v1->milestoneKeys(), $v2->milestoneKeys());
    }

    public function test_a_scene_inside_one_phase_resolves_to_that_phases_environment(): void
    {
        $profile = $this->vesselV2();

        $this->assertSame(
            ['shipyard_hall', 'ok'],
            $profile->environmentForMilestones(['hull_framing', 'shell_plating']),
        );
        $this->assertSame(
            ['design_studio', 'ok'],
            $profile->environmentForMilestones(['concept_sketch']),
        );
    }

    public function test_a_milestone_override_beats_the_environment_of_its_group(): void
    {
        $this->assertSame(
            ['open_water', 'ok'],
            $this->vesselV2()->environmentForMilestones(['sea_trial']),
        );
    }

    public function test_a_scene_spanning_two_places_is_refused_rather_than_guessed(): void
    {
        $this->assertSame(
            [null, 'ambiguous_environment'],
            $this->vesselV2()->environmentForMilestones(['launched', 'sea_trial']),
        );
    }

    public function test_an_unknown_milestone_is_refused(): void
    {
        $this->assertSame(
            [null, 'unknown_milestone'],
            $this->vesselV2()->environmentForMilestones(['hull_framing', 'nothing_like_this']),
        );
        $this->assertSame(
            [null, 'unknown_milestone'],
            $this->vesselV2()->environmentForMilestones([['not', 'a', 'string']]),
        );
    }

    public function test_no_milestone_at_all_is_refused(): void
    {
        $this->assertSame([null, 'no_milestones'], $this->vesselV2()->environmentForMilestones([]));
    }

    public function test_a_profile_without_environments_resolves_to_nothing(): void
    {
        $profile = SceneProfile::load(resource_path('ai/profiles/scene_planning'), 'vessel_v1');

        $this->assertSame(
            [null, 'no_environment'],
            $profile->environmentForMilestones(['hull_framing']),
        );
    }

    private function vesselV2(): SceneProfile
    {
        return SceneProfile::load(resource_path('ai/profiles/scene_planning'), 'vessel_v2');
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function brokenEnvironmentProvider(): iterable
    {
        yield 'khong phai list' => [['key' => 'hall'], 'environments must be a list'];
        yield 'khoa sai dang' => [[['key' => 'Hall', 'label' => 'x', 'prompt' => 'y']], 'Malformed environment key'];
        yield 'khoa trung' => [
            [
                ['key' => 'hall', 'label' => 'x', 'prompt' => 'y'],
                ['key' => 'hall', 'label' => 'x', 'prompt' => 'y'],
            ],
            'Environment key repeats',
        ];
        yield 'thieu nhan' => [[['key' => 'hall', 'prompt' => 'y']], 'is missing label'];
        yield 'thieu prompt' => [[['key' => 'hall', 'label' => 'x']], 'is missing prompt'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenEnvironmentProvider')]
    public function test_a_broken_environment_list_is_refused(mixed $environments, string $needle): void
    {
        $this->write('broken_env', $this->withEnvironments('broken_env', $environments, 'hall'));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($needle, '/').'/');

        SceneProfile::load($this->dir, 'broken_env');
    }

    public function test_a_group_without_an_environment_is_refused(): void
    {
        $this->write('no_env_group', $this->withEnvironments(
            'no_env_group', [['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.']], null,
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/declares no environment/');

        SceneProfile::load($this->dir, 'no_env_group');
    }

    public function test_a_group_pointing_at_an_unknown_environment_is_refused(): void
    {
        $this->write('bad_ref', $this->withEnvironments(
            'bad_ref', [['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.']], 'dockyard',
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/Unknown environment/');

        SceneProfile::load($this->dir, 'bad_ref');
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function nonStringEnvironmentRefProvider(): iterable
    {
        yield 'mang rong' => [[]];
        yield 'mang co phan tu' => [['hall']];
        yield 'so' => [7];
        yield 'boolean' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringEnvironmentRefProvider')]
    public function test_a_group_environment_that_is_not_a_string_is_refused(mixed $ref): void
    {
        $this->write('non_string_group_env', $this->withEnvironments(
            'non_string_group_env',
            [['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.']],
            $ref,
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/Unknown environment/');

        SceneProfile::load($this->dir, 'non_string_group_env');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringEnvironmentRefProvider')]
    public function test_a_milestone_environment_that_is_not_a_string_is_refused(mixed $ref): void
    {
        $this->write('non_string_milestone_env', $this->withEnvironments(
            'non_string_milestone_env',
            [['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.']],
            'hall',
            $ref,
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/Unknown environment/');

        SceneProfile::load($this->dir, 'non_string_milestone_env');
    }

    public function test_a_milestone_may_override_the_environment_of_its_group(): void
    {
        $this->write('milestone_env', $this->withEnvironments(
            'milestone_env',
            [
                ['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.'],
                ['key' => 'quay', 'label' => 'Quay', 'prompt' => 'A quay.'],
            ],
            'hall',
            'quay',
        ));

        $profile = SceneProfile::load($this->dir, 'milestone_env');

        $this->assertSame(['hall', 'quay'], $profile->environmentKeys());
    }

    public function test_a_milestone_pointing_at_an_unknown_environment_is_refused(): void
    {
        $this->write('bad_milestone_ref', $this->withEnvironments(
            'bad_milestone_ref',
            [['key' => 'hall', 'label' => 'Hall', 'prompt' => 'A hall.']],
            'hall',
            'dockyard',
        ));

        $this->expectException(ScenePlanException::class);
        $this->expectExceptionMessageMatches('/Unknown environment/');

        SceneProfile::load($this->dir, 'bad_milestone_ref');
    }

    private function withEnvironments(
        string $version,
        mixed $environments,
        mixed $groupEnvironment,
        mixed $milestoneEnvironment = null,
    ): string {
        $milestone = ['key' => 'welded', 'label' => 'Welded', 'required' => true];

        if ($milestoneEnvironment !== null) {
            $milestone['environment'] = $milestoneEnvironment;
        }

        $group = [
            'key' => 'build',
            'label' => 'Build',
            'milestones' => [$milestone],
        ];

        if ($groupEnvironment !== null) {
            $group['environment'] = $groupEnvironment;
        }

        return json_encode([
            'profile_version' => $version,
            'subject_class' => 'thing',
            'objective' => 'Show the thing being made.',
            'detail_level' => 'step_by_step',
            'scope' => 'full_lifecycle',
            'min_scenes' => 10,
            'max_milestones_per_scene' => 3,
            'environments' => $environments,
            'milestone_groups' => [$group],
        ], JSON_THROW_ON_ERROR);
    }

    private function write(string $version, string $json): void
    {
        file_put_contents($this->dir.'/'.$version.'.json', $json);
    }

    private function body(string $version, string $milestone): string
    {
        return json_encode([
            'profile_version' => $version,
            'subject_class' => 'thing',
            'objective' => 'Show the thing being made.',
            'detail_level' => 'step_by_step',
            'scope' => 'full_lifecycle',
            'min_scenes' => 10,
            'max_milestones_per_scene' => 3,
            'milestone_groups' => [[
                'key' => 'build',
                'label' => 'Build',
                'milestones' => [
                    ['key' => $milestone, 'label' => 'Welding done', 'required' => true],
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function twoGroups(string $version, string $first, string $second): string
    {
        $data = json_decode($this->body($version, $first), true, 512, JSON_THROW_ON_ERROR);

        $data['milestone_groups'][] = [
            'key' => 'finish',
            'label' => 'Finish',
            'milestones' => [
                ['key' => $second, 'label' => 'Same key again', 'required' => false],
            ],
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
