<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\VideoPlanningStage;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\PlanningStageStore;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\ModelSheet\ModelSheetPromptCompiler;
use App\Video\ModelSheet\ModelSheetQaValidator;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure regression tests: no Laravel boot, database, HTTP requests or paid models. */
final class ModelSheetQaRegressionTest extends TestCase
{
    private array $design;

    private array $snapshot;

    private array $qa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->design = self::design();
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
    }

    public function test_valid_source_backed_qa_is_accepted(): void
    {
        $result = $this->validate();
        self::assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    public function test_coverage_cannot_name_an_invented_block(): void
    {
        $this->qa['coverage'][0]['where'] = ['imaginary block'];
        $this->assertRejected('coverage:');
    }

    public function test_coverage_requires_a_link_in_the_named_block(): void
    {
        $this->qa['coverage'][0]['where'] = ['surfaces'];
        $this->assertRejected('coverage:');
    }

    public function test_every_candidate_needs_a_decision(): void
    {
        array_pop($this->qa['views'][0]['decisions']);
        $this->assertRejected('chưa có quyết định');
    }

    public function test_whole_yacht_view_cannot_exclude_a_part_as_out_of_frame(): void
    {
        $this->qa['views'][0]['decisions'][0]['decision'] = 'out_of_frame';
        $this->assertRejected('out_of_frame');
    }

    public function test_blocking_finding_prevents_ready_validation(): void
    {
        $this->qa['findings'][] = ['severity' => 'blocking', 'kind' => 'gap', 'refs' => ['N:N1'], 'message' => 'Missing exterior geometry.'];
        self::assertFalse($this->validate()['ok']);
    }

    public function test_numeric_binding_cannot_assign_beam_to_an_innovation(): void
    {
        $beam = $this->fact('data.canonical.dimensions.beam.value');
        $this->qa['shared'][] = [
            'text' => 'The canyon is {{'.$beam['id'].'}} wide.', 'refs' => ['N:N1'],
            'numbers' => [['fact' => $beam['id'], 'subject' => 'N:N1', 'property' => $beam['property']]],
        ];
        $this->assertRejected('không thuộc chủ thể');
    }

    public function test_numeric_binding_requires_the_correct_property(): void
    {
        $this->qa['shared'][0]['numbers'][0]['property'] = 'beam';
        $this->assertRejected('không phải');
    }

    public function test_display_state_requires_its_own_operational_source(): void
    {
        $this->addStateSource();
        $this->qa['display_state'] = [[
            'part_id' => 'OL:pool_cover', 'part' => 'Pool cover', 'state' => 'Closed.',
            'refs' => ['data.canonical.dimensions.beam'], 'numbers' => [],
        ]];
        $this->assertRejected('nguồn trạng thái của chính nó');
    }

    public function test_used_state_source_requires_a_selected_state(): void
    {
        $this->addStateSource();
        $this->qa['coverage'][] = ['item' => 'OL:pool_cover', 'status' => 'used', 'where' => ['shared'], 'note' => ''];
        $this->assertRejected('display_state không có part_id');
    }

    public function test_valid_display_state_and_its_coverage_are_accepted(): void
    {
        $this->addStateSource();
        $this->qa['display_state'] = [[
            'part_id' => 'OL:pool_cover', 'part' => 'Pool cover', 'state' => 'Closed.',
            'refs' => ['data.canonical.operational_limits.0.otherwise'], 'numbers' => [],
        ]];
        $this->qa['coverage'][] = ['item' => 'OL:pool_cover', 'status' => 'used', 'where' => ['display_state'], 'note' => ''];
        self::assertTrue($this->validate()['ok']);
    }

    public function test_ms8_rejects_a_different_explicit_innovation(): void
    {
        $this->qa['ms8_target'] = ['innovation' => 'N2', 'entities' => ['L:pool'], 'refs' => ['data.yacht_bible.innovations.1.position']];
        $this->assertRejected('thiết kế khai MS8');
    }

    public function test_all_profile_and_plan_numbers_have_typed_facts(): void
    {
        self::assertSame('fraction_of_loa', $this->fact('data.canonical.profile_curve.1.0')['unit']);
        self::assertSame('m_above_dwl', $this->fact('data.canonical.profile_curve.1.1')['unit']);
        self::assertSame('m_offset', $this->fact('data.canonical.plan_outline.1.1')['unit']);
    }

    public function test_unknown_coordinate_units_are_blocked(): void
    {
        $this->design['data']['canonical']['coordinate_system']['y'] = 'positive port';
        self::assertNotNull($this->facts()->coordinateProblem());
    }

    public function test_feet_are_converted_but_explicit_metre_fields_are_preserved(): void
    {
        $this->design['data']['canonical']['coordinate_system']['y'] = 'feet from the centreline, positive port';
        $this->design['data']['canonical']['coordinate_system']['z'] = 'feet above the design waterline';
        $this->snapshot = $this->facts()->snapshot();
        self::assertNull($this->snapshot['coordinate_problem']);
        self::assertEqualsWithDelta(5 * 0.3048, $this->fact('data.canonical.landmarks.canyon_port.z')['value'], 0.000001);
        self::assertEqualsWithDelta(3, $this->fact('data.canonical.decks.0.floor_z_m')['value'], 0.000001);
    }

    public function test_declared_side_conflicting_with_coordinates_is_reported(): void
    {
        $this->design['data']['canonical']['landmarks']['canyon_port']['side'] = 'starboard';
        self::assertNotEmpty($this->facts()->conflicts());
    }

    public function test_explicit_n2_target_is_used_in_compiled_camera(): void
    {
        $this->design['data']['canonical']['model_sheet']['views'][0]['view'] = 'close-up of innovation N2';
        $target = $this->facts()->ms8Target();
        $camera = ModelSheetPromptCompiler::camera(['MS8' => 'Close-up of {target}.'], 'MS8', $target);
        self::assertStringContainsString('innovation N2', $camera);
        self::assertStringNotContainsString('innovation N1', $camera);
    }

    public function test_compiler_substitutes_facts_and_keeps_all_eight_cells(): void
    {
        self::assertTrue($this->validate()['ok']);
        $prompt = $this->compile();
        self::assertStringContainsString('150 m long', $prompt);
        self::assertStringNotContainsString('{{', $prompt);
        foreach (range(1, 8) as $id) {
            self::assertStringContainsString('MS'.$id, $prompt);
        }
    }

    public function test_compiler_rejects_an_oversize_prompt_without_truncating(): void
    {
        $config = self::compilerConfig();
        $config['master']['prompt']['max_chars'] = 10;
        $this->expectException(RuntimeException::class);
        $this->compile($config);
    }

    public function test_service_marks_valid_output_ready_and_hashes_the_exact_prompt(): void
    {
        $result = $this->serviceOutput(json_encode($this->qa, JSON_THROW_ON_ERROR));
        self::assertTrue($result['ready'], implode("\n", $result['validation']['errors']));
        self::assertSame(hash('sha256', $result['prompt']), $result['prompt_sha256']);
    }

    public function test_service_blocks_malformed_json(): void
    {
        $result = $this->serviceOutput('{broken');
        self::assertFalse($result['ready']);
        self::assertNull($result['prompt']);
    }

    public function test_service_does_not_mark_blocking_findings_ready(): void
    {
        $this->qa['findings'][] = ['severity' => 'blocking', 'kind' => 'gap', 'refs' => ['N:N1'], 'message' => 'Missing geometry.'];
        self::assertFalse($this->serviceOutput(json_encode($this->qa, JSON_THROW_ON_ERROR))['ready']);
    }

    // These assertions describe required behaviour. A failure reproduces an unfixed defect.

    public function test_opposite_facing_entity_is_rejected_when_referenced_by_id(): void
    {
        $this->includeInPortView('L:starboard_window');
        $this->assertRejected('hướng ra mạn');
    }

    public function test_opposite_facing_entity_is_also_rejected_via_fact_reference(): void
    {
        $this->includeInPortView($this->fact('data.canonical.landmarks.starboard_window.z')['id']);
        $this->assertRejected('hướng ra mạn');
    }

    public function test_service_does_not_mark_a_wrong_side_fact_reference_ready(): void
    {
        $this->includeInPortView($this->fact('data.canonical.landmarks.starboard_window.z')['id']);
        $result = $this->serviceOutput(json_encode($this->qa, JSON_THROW_ON_ERROR));
        self::assertFalse($result['ready'], 'The wrong-side detail reaches the compiled prompt with ready=true.');
    }

    public function test_opposite_facing_entity_is_also_rejected_via_child_path(): void
    {
        $this->includeInPortView('data.canonical.landmarks.starboard_window.z');
        $this->assertRejected('hướng ra mạn');
    }

    public function test_a_symmetric_partner_does_not_make_the_wrong_facing_part_visible(): void
    {
        $this->design['data']['canonical']['landmarks']['port_window'] = ['x' => 0.7, 'y' => 8, 'z' => 6, 'facing' => 'port'];
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
        $this->includeInPortView('L:starboard_window');
        $this->assertRejected('hướng ra mạn');
    }

    public function test_self_occlusion_cannot_be_disguised_as_its_source_path(): void
    {
        foreach ($this->qa['views'][0]['decisions'] as &$decision) {
            if ($decision['entity'] === 'L:canyon_port') {
                $decision['decision'] = 'occluded';
                $decision['basis'] = 'data.canonical.landmarks.canyon_port';
            }
        }
        unset($decision);
        $this->assertRejected('che');
    }

    public function test_qa_cannot_change_the_ms8_fallback_without_changing_compiler_target(): void
    {
        unset($this->design['data']['canonical']['model_sheet']);
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
        $this->qa['ms8_target'] = ['innovation' => 'N2', 'entities' => ['L:pool'], 'refs' => ['data.yacht_bible.innovations.1.position']];
        self::assertSame('N1', $this->snapshot['ms8']['innovation']);
        self::assertFalse($this->validate()['ok'], 'QA selects N2 while the compiler camera still targets N1.');
    }

    public function test_unknown_explicit_ms8_target_does_not_silently_fall_back_to_n1(): void
    {
        $this->design['data']['canonical']['model_sheet']['views'][0]['view'] = 'close-up of innovation N9';
        self::assertNotSame('N1', $this->facts()->ms8Target()['innovation'], 'An explicit missing target must produce a gap, not a different subject.');
    }

    public function test_innovation_metadata_is_preserved_for_camera_filtering(): void
    {
        $this->design['data']['yacht_bible']['innovations'][1]['side'] = 'starboard';
        $this->design['data']['yacht_bible']['innovations'][1]['level'] = 'deck';
        $entity = $this->facts()->entities()['N:N2'];
        self::assertSame('starboard', $entity['side']);
        self::assertSame('deck', $entity['level']);
    }

    public function test_ranged_feature_preserves_explicit_facing(): void
    {
        $this->design['data']['canonical']['terraces'] = [['name' => 'aft', 'start_x' => 0.7, 'end_x' => 0.9, 'floor_z_m' => 3, 'side' => 'starboard', 'facing' => 'starboard']];
        self::assertSame('starboard', $this->facts()->entities()['R:terraces:aft']['facing']);
    }

    public function test_coordinate_y_requires_the_centreline_origin(): void
    {
        $this->design['data']['canonical']['coordinate_system']['y'] = 'metres from the starboard rail, positive port';
        self::assertNotNull($this->facts()->coordinateProblem(), 'Side inference requires y=0 at the centreline.');
    }

    public function test_ranged_feature_level_conflicting_with_floor_height_is_reported(): void
    {
        $this->design['data']['canonical']['terraces'] = [['name' => 'aft', 'start_x' => 0.7, 'end_x' => 0.9, 'floor_z_m' => 3, 'level' => 'below_waterline']];
        self::assertStringContainsString('floor_z_m', implode("\n", $this->facts()->conflicts()));
    }

    public function test_a_dimension_fact_cannot_be_an_occluder(): void
    {
        $this->occludeCanyonBy($this->fact('data.canonical.dimensions.beam.value')['id']);
        $this->assertRejected('bộ phận vật lý');
    }

    public function test_a_physical_part_can_be_an_occluder(): void
    {
        $this->occludeCanyonBy('L:pool');
        $result = $this->validate();
        self::assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    public function test_an_exterior_colour_text_cannot_be_an_occluder(): void
    {
        $this->design['data']['yacht_bible']['exterior']['color'] = 'Blue';
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
        $this->occludeCanyonBy('data.yacht_bible.exterior.color');
        $this->assertRejected('bộ phận vật lý');
    }

    public function test_a_named_envelope_part_can_be_an_occluder_by_id(): void
    {
        $this->design['data']['yacht_bible']['exterior']['hull'] = 'A steel displacement hull.';
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
        $this->occludeCanyonBy('P:hull');
        $result = $this->validate();
        self::assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    public function test_a_named_envelope_part_can_be_an_occluder_by_path(): void
    {
        $this->design['data']['yacht_bible']['exterior']['hull'] = 'A steel displacement hull.';
        $this->snapshot = $this->facts()->snapshot();
        $this->qa = $this->validQa();
        $this->occludeCanyonBy('data.yacht_bible.exterior.hull');
        $result = $this->validate();
        self::assertTrue($result['ok'], implode("\n", $result['errors']));
    }

    private function occludeCanyonBy(string $basis): void
    {
        foreach ($this->qa['views'][0]['decisions'] as &$decision) {
            if ($decision['entity'] === 'L:canyon_port') {
                $decision['decision'] = 'occluded';
                $decision['basis'] = $basis;
            }
        }
        unset($decision);
    }

    private function facts(): ModelSheetFacts
    {
        return new ModelSheetFacts($this->design, [], self::views(), ['MS2_max_x' => 0.6, 'MS3_min_x' => 0.4, 'MS4_max_x' => 0.33, 'MS5_min_x' => 0.67, 'MS8_near_loa' => 0.1]);
    }

    private function fact(string $path): array
    {
        foreach ($this->snapshot['facts'] as $fact) {
            if ($fact['path'] === $path) {
                return $fact;
            }
        }
        self::fail('Missing fixture fact: '.$path);
    }

    private function validate(): array
    {
        return (new ModelSheetQaValidator($this->snapshot, $this->design, [], array_column(self::views(), 'id'), 5))->validate($this->qa);
    }

    private function assertRejected(string $message): void
    {
        $result = $this->validate();
        self::assertFalse($result['ok'], 'Invalid QA was accepted.');
        self::assertStringContainsString($message, implode("\n", $result['errors']));
    }

    private function includeInPortView(string $ref): void
    {
        $this->qa['views'][0]['sentences'][] = self::sentence('A recessed starboard-facing window.', [$ref]);
        if (str_starts_with($ref, 'data.')) {
            // A descendant path must resolve to its owner, even without an explicit decision.
            return;
        }
        foreach ($this->qa['views'][0]['decisions'] as &$decision) {
            if ($decision['entity'] === 'L:starboard_window') {
                $decision['decision'] = 'include';
            }
        }
        unset($decision);
        if (! in_array('L:starboard_window', array_column($this->qa['views'][0]['decisions'], 'entity'), true)) {
            $this->qa['views'][0]['decisions'][] = ['entity' => 'L:starboard_window', 'decision' => 'include', 'basis' => null, 'note' => ''];
        }
    }

    private function addStateSource(): void
    {
        $this->design['data']['canonical']['operational_limits'] = [['feature' => 'pool_cover', 'otherwise' => 'The pool cover is closed.']];
        $this->snapshot = $this->facts()->snapshot();
    }

    private function validQa(): array
    {
        $loa = $this->fact('data.canonical.dimensions.loa.value');
        $entities = array_column($this->snapshot['entities'], 'id');
        $qa = [
            'shared' => [
                ['text' => 'A yacht {{'.$loa['id'].'}} long.', 'refs' => ['data.canonical.dimensions.loa'], 'numbers' => [['fact' => $loa['id'], 'subject' => 'yacht', 'property' => 'loa']]],
                self::sentence('A recessed canyon, an aft pool and separated side windows form the exterior.', $entities),
            ],
            'surfaces' => [self::sentence('Blue exterior paint.', ['data.yacht_bible.colors.0'])],
            'display_state' => [], 'views' => [],
            'ms8_target' => ['innovation' => 'N1', 'entities' => ['L:canyon_port'], 'refs' => ['data.yacht_bible.innovations.0.position']],
            'critical_absences' => [], 'coverage' => [], 'changes' => [], 'findings' => [],
        ];
        foreach ($this->snapshot['candidates'] as $id => $candidates) {
            $qa['views'][] = ['view_id' => $id, 'sentences' => [self::sentence('The continuous outer envelope is visible.', ['data.yacht_bible.exterior.silhouette'])],
                'decisions' => array_map(static fn (array $c): array => ['entity' => $c['entity'], 'decision' => 'shared', 'basis' => null, 'note' => ''], $candidates)];
        }
        foreach ($this->snapshot['coverage'] as $item) {
            $qa['coverage'][] = ['item' => $item['id'], 'status' => 'used', 'where' => ['shared'], 'note' => ''];
        }

        return $qa;
    }

    private function compile(?array $config = null): string
    {
        return (new ModelSheetPromptCompiler($config ?? self::compilerConfig(), array_column($this->snapshot['facts'], null, 'id')))
            ->compile($this->qa, self::views(), false, $this->snapshot['ms8']);
    }

    private function serviceOutput(string $raw): array
    {
        // Only output() is exercised: no application boot, provider client or stage-store writes.
        $previous = Container::getInstance();
        $container = new Container;
        $config = self::compilerConfig();
        $config['qa'] = ['execution' => 'test-qa', 'negatives_target' => 5];
        $container->instance('config', new Repository(['video' => ['superyacht' => ['model_sheet' => $config]]]));
        Container::setInstance($container);
        try {
            $set = new VideoPlanningStage;
            $set->id = 'in-memory-fixture';
            $set->output_json = ['facts' => $this->snapshot, 'views' => self::views(), 'layout_guide' => false];

            return (new ModelSheetQaService(new PlanningStageStore))->output($set, $raw, $this->design, []);
        } finally {
            Container::setInstance($previous);
        }
    }

    private static function sentence(string $text, array $refs): array
    {
        return ['text' => $text, 'refs' => $refs, 'numbers' => []];
    }

    private static function views(): array
    {
        $out = [];
        foreach (range(1, 8) as $i) {
            $out[] = ['id' => 'MS'.$i, 'view' => 'View '.$i, 'row' => intdiv($i - 1, 4) + 1, 'column' => ($i - 1) % 4 + 1,
                'x' => (($i - 1) % 4) * 960, 'y' => intdiv($i - 1, 4) * 1080, 'width' => 960, 'height' => 1080];
        }

        return $out;
    }

    private static function compilerConfig(): array
    {
        return ['master' => ['size' => '3840x2160', 'rows' => 2, 'columns' => 4, 'cell' => ['width' => 960, 'height' => 1080], 'detail_views' => ['MS8'],
            'prompt' => ['layout' => 'ONE {width}x{height} image, {rows} rows by {columns} columns.', 'panel' => 'Row {row}, column {column}: {id}, {view}. {camera}',
                'framing' => 'Keep the whole yacht within each panel.', 'detail_framing' => '{id} is a detail.', 'rules' => ['Same yacht in all views.'],
                'presentation' => 'Whole hull out of water.', 'guide_caption' => 'Layout only.', 'max_chars' => 20000]],
            'cameras' => array_replace(array_fill_keys(array_column(self::views(), 'id'), 'Fixed camera.'), ['MS8' => 'Close-up of {target}.']),
            'occlusion' => 'Do not reveal hidden parts.', 'suffix' => 'neutral studio'];
    }

    private static function design(): array
    {
        return ['data' => ['canonical' => [
            'coordinate_system' => ['x' => 'fraction of LOA from bow', 'y' => 'metres from the centreline, positive port', 'z' => 'metres above the design waterline'],
            'dimensions' => ['loa' => ['value' => 150, 'unit' => 'm'], 'beam' => ['value' => 20, 'unit' => 'm'], 'draft' => ['value' => 5, 'unit' => 'm']],
            'decks' => [['name' => 'Main', 'floor_z_m' => 3, 'ceiling_z_m' => 7]],
            'profile_curve' => [[0, 3], [0.5, 7], [1, 3]], 'plan_outline' => [[0, 0], [0.5, 10], [1, 4], [1, -4]],
            'landmarks' => ['canyon_port' => ['x' => 0.5, 'y' => 3, 'z' => 5, 'facing' => 'port'],
                'starboard_window' => ['x' => 0.7, 'y' => -8, 'z' => 6, 'facing' => 'starboard'], 'pool' => ['x' => 0.8, 'y' => 0, 'z' => 3]],
            'design_parameters' => ['canyon_width' => ['value' => 3, 'unit' => 'm']],
            'model_sheet' => ['views' => [['id' => 'MS8', 'view' => 'close-up of innovation N1']]],
        ], 'yacht_bible' => ['colors' => [['name' => 'Blue']], 'exterior' => ['silhouette' => 'A continuous outer envelope.'],
            'innovations' => [['id' => 'N1', 'name' => 'Canyon', 'position' => 'The canyon at x=0.4 to x=0.6 includes canyon_port.'],
                ['id' => 'N2', 'name' => 'Pool', 'position' => 'The pool at x=0.8 lies on the aft deck.']]]]];
    }
}
