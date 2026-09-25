<?php

namespace Tests\Feature\Video;

use App\Video\Screenplay\ScreenplayText;
use Tests\TestCase;

class ScreenplayTextV3Test extends TestCase
{
    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function v2(): array
    {
        return $this->read(resource_path('ai/screenplay/v2/04_worked_example.json'))
            + ['schema_version' => 'screenplay_v2'];
    }

    /** @return array<string, mixed> */
    private function v3(): array
    {
        return $this->read(resource_path('ai/screenplay/v3/04_worked_example.json'))
            + ['schema_version' => 'screenplay_v3'];
    }

    public function test_the_v2_view_is_byte_for_byte_what_it_was_before_v3_existed(): void
    {
        $baseline = (string) file_get_contents(__DIR__.'/../../Fixtures/screenplay/v2_render_baseline.txt');

        $this->assertNotSame('', $baseline, 'the baseline must be captured, not regenerated from the code under test');
        $this->assertSame($baseline, ScreenplayText::render($this->v2()));
    }

    public function test_a_stored_v2_row_still_reads_when_the_author_is_configured_for_v3(): void
    {
        config(['video.screenplay.contract_version' => 'screenplay_v3']);

        $baseline = (string) file_get_contents(__DIR__.'/../../Fixtures/screenplay/v2_render_baseline.txt');

        $this->assertSame($baseline, ScreenplayText::render($this->v2()));
    }

    public function test_the_v2_view_carries_nothing_v3_introduced(): void
    {
        $text = ScreenplayText::render($this->v2());

        $this->assertStringNotContainsString('Build state', $text);
        $this->assertStringNotContainsString('COVERAGE', $text);
    }

    public function test_the_v3_view_keeps_everything_the_v2_view_showed(): void
    {
        $text = ScreenplayText::render($this->v3());

        foreach (['DESIGN THESIS', 'PREMISE', 'CHARACTERS', 'LOCATIONS',
            'Sound:', '» Cannot be cut:', '» Leads to    :', 'ESTIMATED TOTAL'] as $section) {
            $this->assertStringContainsString($section, $text);
        }
    }

    public function test_the_v3_view_names_the_kind_of_every_participant(): void
    {
        $screenplay = $this->v3();
        $text = ScreenplayText::render($screenplay);

        foreach ($screenplay['characters'] as $character) {
            $this->assertStringContainsString(
                "[{$character['id']} · {$character['role']} · {$character['kind']}]",
                $text,
            );
        }

        foreach (['· object]', '· person]', '· group]'] as $kind) {
            $this->assertStringContainsString($kind, $text);
        }
    }

    public function test_each_scene_shows_its_own_build_state_and_no_other(): void
    {
        $screenplay = $this->v3();
        $text = ScreenplayText::render($screenplay);
        $blocks = array_slice(explode(str_repeat('=', 70), $text), 1);

        foreach ($screenplay['scenes'] as $index => $scene) {
            $block = $blocks[$index];

            $this->assertStringContainsString(strtoupper($scene['id']), $block);

            if ($scene['build_state'] === null) {
                $this->assertStringContainsString('Build state: not shown in this scene', $block);

                continue;
            }

            $this->assertStringContainsString(
                'Build state: '.$scene['build_state']['subject_id'].' — '.$scene['build_state']['state'],
                $block,
            );

            foreach ($screenplay['scenes'] as $other) {
                if ($other['id'] !== $scene['id'] && $other['build_state'] !== null) {
                    $this->assertStringNotContainsString($other['build_state']['state'], $block);
                }
            }
        }
    }

    public function test_a_null_build_state_is_never_presented_as_inherited(): void
    {
        $screenplay = $this->v3();
        $previous = null;

        foreach ($screenplay['scenes'] as $scene) {
            if ($scene['build_state'] === null) {
                continue;
            }

            $previous = $scene['build_state']['state'];

            break;
        }

        $this->assertNotNull($previous);

        $screenplay['scenes'][5]['build_state'] = null;
        $blocks = array_slice(explode(str_repeat('=', 70), ScreenplayText::render($screenplay)), 1);

        $this->assertStringContainsString('Build state: not shown in this scene', $blocks[5]);
        $this->assertStringContainsString('does not carry over from the scene before', $blocks[5]);
        $this->assertStringNotContainsString($previous, $blocks[5]);
    }

    public function test_a_scene_that_declares_no_build_state_is_shown_as_missing(): void
    {
        $screenplay = $this->v3();
        unset($screenplay['scenes'][0]['build_state']);

        $this->assertStringContainsString(
            'Build state: MISSING — this scene does not declare one.',
            ScreenplayText::render($screenplay),
        );
    }

    public function test_the_v3_view_lists_every_coverage_item_with_its_mode_and_evidence(): void
    {
        $screenplay = $this->v3();
        $text = ScreenplayText::render($screenplay);
        $coverage = substr($text, (int) strrpos($text, 'COVERAGE'));

        foreach ($screenplay['coverage'] as $item) {
            $this->assertStringContainsString($item['coverage_id'].'   ['.$item['mode'], $coverage);
            $this->assertStringContainsString($item['evidence'], $coverage);

            foreach ($item['scene_ids'] as $sceneId) {
                $this->assertStringContainsString($sceneId, $coverage);
            }
        }
    }

    /** @dataProvider coverageModes */
    public function test_each_coverage_mode_reads_correctly(string $mode, array $sceneIds, bool $needsReview): void
    {
        $screenplay = $this->v3();
        $screenplay['coverage'][0] = [
            'coverage_id' => 'cov_design_need',
            'mode' => $mode,
            'scene_ids' => $sceneIds,
            'evidence' => 'A reason recorded for the editor to read.',
        ];

        $text = ScreenplayText::render($screenplay);
        $coverage = substr($text, (int) strrpos($text, 'COVERAGE'));
        $line = '  cov_design_need   ['.$mode.($sceneIds === [] ? '' : ' · '.implode(', ', $sceneIds)).']';

        $this->assertStringContainsString($line, $coverage);

        if ($needsReview) {
            $this->assertStringContainsString('SET ASIDE — needs editorial review', $coverage);
            $this->assertStringContainsString('This is a request, not an approval.', $coverage);

            return;
        }

        $this->assertStringNotContainsString('SET ASIDE', $coverage);
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: bool}> */
    public static function coverageModes(): array
    {
        return [
            'shown' => ['shown', ['sc_01'], false],
            'transition' => ['transition', ['sc_01', 'sc_02'], false],
            'not_applicable' => ['not_applicable', [], true],
        ];
    }

    public function test_the_label_the_renderer_writes_never_reads_as_an_approval(): void
    {
        $screenplay = $this->v3();
        $screenplay['coverage'][0]['mode'] = 'not_applicable';
        $screenplay['coverage'][0]['scene_ids'] = [];
        $screenplay['coverage'][0]['evidence'] = 'The yard accepted an equivalent detail, so this item was approved elsewhere.';

        $text = ScreenplayText::render($screenplay);

        $this->assertStringContainsString(
            'SET ASIDE — needs editorial review. This is a request, not an approval.',
            $text,
            'the renderer must label the mode itself, not leave the reader to judge from the evidence',
        );

        $this->assertStringContainsString(
            $screenplay['coverage'][0]['evidence'],
            $text,
            'the evidence is the writer\'s words and passes through untouched, approval wording included',
        );
    }

    public function test_a_build_state_stored_with_the_wrong_type_is_shown_as_unreadable(): void
    {
        $screenplay = $this->v3();
        $screenplay['scenes'][0]['build_state'] = 'hull plated to the sheer';

        $text = ScreenplayText::render($screenplay);

        $this->assertStringContainsString(
            'Build state: UNREADABLE — stored as string, expected an object or null.',
            $text,
        );
        $this->assertStringNotContainsString('not shown in this scene', $text);
    }

    public function test_the_legacy_and_unknown_branches_are_untouched(): void
    {
        $this->assertStringContainsString(
            'LEGACY SCREENPLAY',
            ScreenplayText::render(['logline' => 'An older row with no version.']),
        );

        $this->assertSame(
            'Screenplay version screenplay_v9 is not supported by this view.',
            ScreenplayText::render(['schema_version' => 'screenplay_v9']),
        );
    }
}
