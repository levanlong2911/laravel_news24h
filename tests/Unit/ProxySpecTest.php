<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\Proxy\ProxySpecCompiler;
use App\Video\Proxy\ProxySpecValidator;
use PHPUnit\Framework\TestCase;

/** ProxySpec: bien dich tu nguon dong bang + mapping, va kiem. Thuan, khong DB, khong model. */
final class ProxySpecTest extends TestCase
{
    public function test_confirmed_mapping_compiles_ready_and_validates(): void
    {
        $spec = $this->confirmed($this->mapping());

        self::assertTrue($spec['ready'], json_encode(array_filter($spec['gaps'], static fn (array $g): bool => $g['severity'] === 'blocking'), JSON_UNESCAPED_UNICODE));
        self::assertSame([], (new ProxySpecValidator)->errors($spec));
        self::assertSame(['N1_slot', 'N2_facets', 'keel_blocks'], array_values(array_column(array_filter($spec['geometry']['parts'], static fn (array $p): bool => $p['origin'] === 'mapping'), 'id')));
    }

    public function test_changing_a_design_dimension_changes_the_compiled_geometry(): void
    {
        $before = $this->compile($this->mapping());
        $design = self::design();
        $design['data']['canonical']['dimensions']['slot_width']['value'] = 7;
        $after = $this->compile($this->mapping(), $design);

        self::assertSame(6.0, $this->part($before, 'N1_slot')['params']['width']['value']);
        self::assertSame(7.0, $this->part($after, 'N1_slot')['params']['width']['value']);
        self::assertNotSame($before['spec_sha256'], $after['spec_sha256']);
    }

    public function test_positions_are_converted_once_to_metres_from_the_bow(): void
    {
        $slot = $this->part($this->compile($this->mapping()), 'N1_slot');

        self::assertSame(40.0, $slot['params']['from_x']['value']);
        self::assertSame(60.0, $slot['params']['to_x']['value']);
        self::assertSame('design', $slot['params']['width']['basis']);
    }

    public function test_each_side_keeps_its_own_landmark_and_is_never_mirrored_silently(): void
    {
        $facets = $this->part($this->compile($this->mapping()), 'N2_facets');
        self::assertSame([['mirror_port', 3.0, 'port'], ['mirror_starboard', -3.0, 'starboard']], array_map(static fn (array $i): array => [$i['landmark'], $i['y'], $i['side']], $facets['instances']));

        $mapping = $this->mapping();
        $mapping['parts'][1]['instances'] = ['mirror_port'];
        $single = $this->compile($mapping);
        self::assertCount(1, $this->part($single, 'N2_facets')['instances']);

        $mapping['parts'][1]['mirror'] = true;
        $mapping['parts'][1]['mirror_note'] = 'thiết kế chỉ có mốc mạn trái';
        $mirrored = $this->compile($mapping);
        $instances = $this->part($mirrored, 'N2_facets')['instances'];
        self::assertSame([3.0, -3.0], array_column($instances, 'y'));
        self::assertSame('starboard', $instances[1]['side']);
        self::assertTrue($instances[1]['mirrored']);
        self::assertContains('N2_facets.mirror', $this->pending($mirrored));
        self::assertSame([], (new ProxySpecValidator)->errors($mirrored));
    }

    public function test_missing_y_is_not_defaulted_to_the_centreline(): void
    {
        $mapping = $this->mapping();
        $mapping['parts'][] = ['id' => 'hatch', 'op' => 'marking', 'registry' => 'hull_shell', 'facing' => 'aft', 'instances' => ['hatch']];
        unset($mapping['unused']['hatch']);
        $spec = $this->compile($mapping);

        self::assertNull($this->part($spec, 'hatch')['instances'][0]['y']);
        self::assertTrue($this->hasGap($spec, 'blocking', 'hatch', 'không mặc định trục giữa'));

        $mapping['parts'][3]['centreline'] = true;
        $centred = $this->compile($mapping);
        self::assertSame(0.0, $this->part($centred, 'hatch')['instances'][0]['y']);
        self::assertContains('hatch.centreline', $this->pending($centred));

        // Xac nhan centreline phai co hieu luc nhu moi gia dinh khac.
        $confirmed = $this->confirmed($mapping);
        self::assertSame([], $this->pending($confirmed));
        self::assertTrue($confirmed['ready']);
        self::assertSame([], (new ProxySpecValidator)->errors($confirmed));
    }

    public function test_confirmations_survive_a_json_round_trip(): void
    {
        $first = $this->compile($this->mapping());
        $mapping = $this->mapping();
        $mapping['confirmed'] = array_column(array_filter($first['assumptions'], static fn (array $a): bool => $a['requires_confirmation']), 'value', 'id');
        // Luu DB roi doc lai: 25.0 thanh 25, 6.0 thanh 6.
        $stored = json_decode((string) json_encode($mapping), true);

        self::assertSame(25, $stored['confirmed']['BS05.scale']);
        self::assertTrue($this->compile($stored)['ready']);
    }

    public function test_quoted_dimension_needs_an_explicit_pick_and_confirmation(): void
    {
        $mapping = $this->mapping();
        unset($mapping['parts'][1]['params']['size_x']['pick']);
        $spec = $this->compile($mapping);
        self::assertTrue($this->hasGap($spec, 'blocking', 'N2_facets.size_x', 'pick'));

        $ok = $this->compile($this->mapping());
        self::assertSame('approximate', $this->part($ok, 'N2_facets')['params']['size_x']['basis']);
        self::assertSame(3.0, $this->part($ok, 'N2_facets')['params']['size_x']['value']);
        self::assertContains('N2_facets.size_x', $this->pending($ok));
    }

    public function test_deck_check_uses_enclosed_segments_and_reports_real_conflicts(): void
    {
        $spec = $this->compile($this->mapping());
        $upper = $spec['geometry']['decks'][1];
        self::assertSame([[20.0, 40.0], [60.0, 80.0]], $upper['segments']);
        self::assertSame(0, $upper['envelope_conflicts']);
        self::assertFalse($this->hasGap($spec, 'warning', 'data.canonical.decks.1', ''));

        $design = self::design();
        unset($design['data']['canonical']['decks'][1]['enclosed_segments']);
        $filled = $this->compile($this->mapping(), $design);
        self::assertGreaterThan(0, $filled['geometry']['decks'][1]['envelope_conflicts']);
        self::assertTrue($this->hasGap($filled, 'blocking', 'data.canonical.decks.1', 'mâu thuẫn thiết kế'));
    }

    public function test_deck_envelope_conflict_blocks_even_with_every_assumption_confirmed(): void
    {
        $design = self::design();
        unset($design['data']['canonical']['decks'][1]['enclosed_segments']);
        $first = $this->compile($this->mapping(), $design);
        $mapping = $this->mapping();
        $mapping['confirmed'] = array_column(array_filter($first['assumptions'], static fn (array $a): bool => $a['requires_confirmation']), 'value', 'id');
        $spec = $this->compile($mapping, $design);

        self::assertSame([], $this->pending($spec));
        self::assertFalse($spec['ready']);
        self::assertSame([], (new ProxySpecValidator)->errors($spec));
    }

    public function test_identity_innovation_cannot_be_omitted(): void
    {
        $mapping = $this->mapping();
        $mapping['parts'][0] = ['id' => 'N1_slot', 'op' => 'omit', 'innovation' => 'N1', 'importance' => 'identity', 'reason' => 'Skip this identity part.'];
        $spec = $this->confirmed($mapping);

        self::assertFalse($spec['ready']);
        self::assertTrue($this->hasGap($spec, 'blocking', 'N:N1', 'chưa có bộ phận dựng'));
        self::assertTrue($this->hasGap($spec, 'blocking', 'N1_slot', 'Không được bỏ'));

        $mapping = $this->mapping();
        $mapping['parts'][] = ['id' => 'fin', 'op' => 'omit', 'importance' => 'silhouette', 'reason' => 'không cần'];
        self::assertTrue($this->hasGap($this->confirmed($mapping), 'blocking', 'fin', 'Không được bỏ'));
    }

    public function test_ready_spec_with_a_missing_required_parameter_is_rejected(): void
    {
        $spec = $this->confirmed($this->mapping());
        self::assertTrue($spec['ready']);
        $spec['geometry']['parts'][3]['params']['width']['value'] = null;
        $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);

        self::assertContains('Spec ghi ready nhưng N1_slot.width không có giá trị số hợp lệ.', (new ProxySpecValidator)->errors($spec));
    }

    public function test_malformed_structure_returns_errors_instead_of_throwing(): void
    {
        $spec = $this->confirmed($this->mapping());
        $spec['geometry']['hull']['profile'] = 'không phải danh sách';
        $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);
        $errors = (new ProxySpecValidator)->errors($spec);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('/geometry/hull/profile', implode("\n", $errors));
    }

    public function test_instance_position_must_match_its_landmark_on_every_axis(): void
    {
        foreach (['X' => 75.0, 'z' => 12.0, 'y' => 4.0] as $axis => $value) {
            $spec = $this->confirmed($this->mapping());
            self::assertTrue($spec['ready']);
            $spec['geometry']['parts'][4]['instances'][0][$axis] = $value;
            $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);
            $errors = implode("\n", (new ProxySpecValidator)->errors($spec));

            self::assertStringContainsString('khác nguồn', $errors, "trục {$axis}");
        }
    }

    public function test_camera_set_must_match_the_master_views_exactly(): void
    {
        $missing = $this->confirmed($this->mapping());
        array_pop($missing['cameras']);
        $missing['spec_sha256'] = ProxySpecCompiler::sha256($missing);
        self::assertStringContainsString('bộ camera không đủ', implode("\n", (new ProxySpecValidator)->errors($missing)));

        $extra = $this->confirmed($this->mapping());
        $extra['cameras'][7]['view'] = 'MS9';
        $extra['spec_sha256'] = ProxySpecCompiler::sha256($extra);
        self::assertStringContainsString('Camera MS9 không thuộc', implode("\n", (new ProxySpecValidator)->errors($extra)));

        $duplicate = $this->confirmed($this->mapping());
        $duplicate['cameras'][7]['view'] = 'MS1';
        $duplicate['spec_sha256'] = ProxySpecCompiler::sha256($duplicate);
        self::assertStringContainsString('Camera MS1 trùng', implode("\n", (new ProxySpecValidator)->errors($duplicate)));
    }

    public function test_validator_rechecks_the_omit_rule_on_a_stored_spec(): void
    {
        $spec = $this->confirmed($this->mapping());
        $spec['geometry']['parts'][3]['op'] = 'omit';
        $spec['geometry']['parts'][3]['params'] = [];
        $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);
        $errors = implode("\n", (new ProxySpecValidator)->errors($spec));

        self::assertStringContainsString('N1_slot: không được bỏ (omit)', $errors);
        self::assertStringContainsString('đổi mới N1 không có bộ phận dựng', $errors);
    }

    public function test_scale_model_without_a_design_ratio_needs_a_confirmed_assumption(): void
    {
        $unconfirmed = $this->compile($this->mapping());
        $bs05 = $unconfirmed['build_states'][0];
        self::assertSame([25.0, 'assumption'], [$bs05['scale'], $bs05['scale_basis']]);
        self::assertContains('BS05.scale', $this->pending($unconfirmed));
        self::assertFalse($unconfirmed['ready']);

        $spec = $this->confirmed($this->mapping());
        self::assertTrue($spec['ready']);
        $spec['build_states'][0]['scale'] = null;
        $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);
        self::assertStringContainsString('BS05 (mô hình tỉ lệ) chưa có tỉ lệ', implode("\n", (new ProxySpecValidator)->errors($spec)));

        $design = self::design();
        $design['data']['canonical']['design_parameters'] = ['scale_model_ratio' => ['value' => 20, 'unit' => 'full-size units per model unit']];
        $fromDesign = $this->compile($this->mapping(), $design)['build_states'][0];
        self::assertSame([20.0, 'design'], [$fromDesign['scale'], $fromDesign['scale_basis']]);
    }

    public function test_values_in_use_must_equal_the_confirmed_assumption_values(): void
    {
        $cases = [
            'tỉ lệ BS05' => [static fn (array &$s) => $s['build_states'][0]['scale'] = 100.0, 'Tỉ lệ BS05 = 100 không trùng'],
            'tham số nhập tay' => [static fn (array &$s) => $s['geometry']['parts'][4]['params']['recess']['value'] = 0.5, 'N2_facets.recess = 0.5 không trùng'],
            'số trích dẫn' => [static fn (array &$s) => $s['geometry']['parts'][4]['params']['size_x']['value'] = 4.0, 'N2_facets.size_x = 4 không trùng'],
            'hình thân' => [static fn (array &$s) => $s['geometry']['hull']['shape']['G2']['value'] = 8.0, 'Hình thân G2 không trùng'],
        ];

        foreach ($cases as $label => [$mutate, $message]) {
            $spec = $this->confirmed($this->mapping());
            self::assertTrue($spec['ready']);
            $mutate($spec);
            $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);

            self::assertStringContainsString($message, implode("\n", (new ProxySpecValidator)->errors($spec)), $label);
        }
    }

    public function test_config_code_parameter_must_equal_its_assumption(): void
    {
        $mapping = $this->mapping();
        $mapping['parts'][] = ['id' => 'terrace_wall_probe', 'op' => 'bulwark', 'registry' => 'hull_shell',
            'params' => ['from_x' => ['landmark' => 'bow_tip', 'axis' => 'x'], 'to_x' => ['landmark' => 'slot_fwd', 'axis' => 'x'], 'height' => ['code' => 'G8'], 'thickness' => ['code' => 'G4']]];
        $spec = $this->confirmed($mapping);
        self::assertTrue($spec['ready'], json_encode(array_filter($spec['gaps'], static fn (array $g): bool => $g['severity'] === 'blocking'), JSON_UNESCAPED_UNICODE));
        self::assertSame([], (new ProxySpecValidator)->errors($spec));

        // G8 la met: dung 1 m; G4 la phan LOA: 0.05 x LOA 100 = 5 m.
        $part = $this->part($spec, 'terrace_wall_probe');
        self::assertSame([1.0, 5.0], [$part['params']['height']['value'], $part['params']['thickness']['value']]);

        $cases = [
            'G8 (mét) bị nhân LOA' => ['height', 100.0, 'terrace_wall_probe.height = 100 không trùng giá trị của giả định G8'],
            'G8 sai giá trị' => ['height', 3.0, 'terrace_wall_probe.height = 3 không trùng giá trị của giả định G8'],
            'G4 (phần LOA) để giá trị thô' => ['thickness', 0.05, 'terrace_wall_probe.thickness = 0.05 không trùng giá trị của giả định G4'],
        ];

        foreach ($cases as $label => [$param, $value, $message]) {
            $tampered = $spec;
            $tampered['geometry']['parts'][6]['params'][$param]['value'] = $value;
            $tampered['spec_sha256'] = ProxySpecCompiler::sha256($tampered);

            self::assertStringContainsString($message, implode("\n", (new ProxySpecValidator)->errors($tampered)), $label);
        }
    }

    public function test_every_side_label_must_match_y_including_centre(): void
    {
        $spec = $this->confirmed($this->mapping());
        $spec['geometry']['parts'][4]['instances'][0]['side'] = 'centre';
        $spec['spec_sha256'] = ProxySpecCompiler::sha256($spec);

        self::assertStringContainsString('ghi mạn centre nhưng y = 3', implode("\n", (new ProxySpecValidator)->errors($spec)));
    }

    public function test_nominal_cameras_are_flagged_as_not_frozen(): void
    {
        $spec = $this->compile($this->mapping());

        self::assertSame('config:video.superyacht.model_sheet.frames', $spec['cameras'][0]['source']);
        self::assertTrue($this->hasGap($spec, 'warning', 'cameras', 'chưa đóng băng'));
    }

    public function test_innovation_without_a_part_blocks(): void
    {
        $mapping = $this->mapping();
        array_shift($mapping['parts']);
        $spec = $this->compile($mapping);

        self::assertFalse($spec['ready']);
        self::assertTrue($this->hasGap($spec, 'blocking', 'N:N1', 'chưa có bộ phận dựng'));
    }

    public function test_build_state_part_without_geometry_blocks(): void
    {
        $mapping = $this->mapping();
        array_pop($mapping['parts']);
        $spec = $this->compile($mapping);

        self::assertTrue($this->hasGap($spec, 'blocking', 'BS07:keel_blocks', 'chưa có bộ phận dựng'));
        self::assertSame(['hull', 'keel_blocks'], $this->compile($this->mapping())['build_states'][1]['parts']);
    }

    public function test_cut_whose_floor_is_above_the_envelope_is_rejected(): void
    {
        $mapping = $this->mapping();
        $mapping['parts'][0]['params']['floor_z'] = ['value' => 30, 'unit' => 'm', 'note' => 'sai'];
        $errors = (new ProxySpecValidator)->errors($this->compile($mapping));

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'không cắt vào vỏ') || str_contains($e, 'ngoài khoảng')));
    }

    public function test_tampered_spec_is_rejected(): void
    {
        $spec = $this->confirmed($this->mapping());
        $spec['geometry']['parts'][3]['params']['width']['value'] = 2.0;

        self::assertContains('spec_sha256 không khớp nội dung — spec đã bị sửa sau khi biên dịch.', (new ProxySpecValidator)->errors($spec));
    }

    public function test_confirmation_is_bound_to_the_confirmed_value(): void
    {
        $spec = $this->confirmed($this->mapping());
        self::assertTrue($spec['ready']);

        $mapping = $this->mapping();
        $mapping['confirmed'] = array_column(array_filter($spec['assumptions'], static fn (array $a): bool => $a['requires_confirmation']), 'value', 'id');
        $mapping['parts'][1]['params']['recess']['value'] = 0.4;
        $changed = $this->compile($mapping);

        self::assertFalse($changed['ready']);
        self::assertContains('N2_facets.recess', $this->pending($changed));
    }

    public function test_ineligible_source_never_becomes_ready(): void
    {
        $source = $this->source();
        $source['eligible'] = false;
        $source['problems'] = ['Master luồng cũ'];
        $spec = (new ProxySpecCompiler)->compile($source, self::config(), $this->mapping());

        self::assertFalse($spec['ready']);
        self::assertTrue($this->hasGap($spec, 'blocking', 'nguồn', 'Master luồng cũ'));
    }

    public function test_gltf_transform_is_a_handedness_swap_that_keeps_port_on_positive_z(): void
    {
        $matrix = $this->compile($this->mapping())['coordinates']['to_gltf'];
        $port = [30.0, 3.0, 9.5, 1];
        $gltf = array_map(static fn (array $row): float => array_sum(array_map(static fn ($a, $b) => $a * $b, $row, $port)), $matrix);

        self::assertSame([30.0, 9.5, 3.0, 1.0], $gltf);
        $m = $matrix;
        $det = $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1]) - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0]) + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
        self::assertSame(-1, $det);
    }

    public function test_cameras_use_the_model_sheet_cell_and_master_ms8_target(): void
    {
        $cameras = array_column($this->compile($this->mapping())['cameras'], null, 'view');

        self::assertSame([960, 1080], [$cameras['MS1']['width'], $cameras['MS1']['height']]);
        self::assertSame(['mirror_port', 'mirror_starboard'], $cameras['MS8']['target']['landmarks']);
        self::assertSame(['G7'], $cameras['MS2']['assumptions']);
    }

    public function test_js_fixture_matches_the_compiler(): void
    {
        $path = dirname(__DIR__).'/js/fixtures/proxy-spec.json';

        self::assertFileExists($path);
        self::assertSame(self::fixtureSpec()['spec_sha256'], json_decode((string) file_get_contents($path), true)['spec_sha256'], 'Fixture JS đã cũ — chạy: php tests/js/fixtures/make-spec.php');
    }

    /** Spec da xac nhan cua fixture: dung chung cho test JS (tests/js/fixtures/proxy-spec.json). */
    public static function fixtureSpec(): array
    {
        $test = new self('fixture');

        return $test->confirmed($test->mapping());
    }

    /** @return array<string, mixed> */
    private function confirmed(array $mapping): array
    {
        $first = $this->compile($mapping);
        $mapping['confirmed'] = array_column(array_filter($first['assumptions'], static fn (array $a): bool => $a['requires_confirmation']), 'value', 'id');

        return $this->compile($mapping);
    }

    /** @return array<string, mixed> */
    private function compile(array $mapping, ?array $design = null): array
    {
        return (new ProxySpecCompiler)->compile($this->source($design), self::config(), $mapping);
    }

    /** @return list<string> */
    private function pending(array $spec): array
    {
        return array_values(array_column(array_filter($spec['assumptions'], static fn (array $a): bool => $a['requires_confirmation'] && ! $a['confirmed']), 'id'));
    }

    private function hasGap(array $spec, string $severity, string $subject, string $text): bool
    {
        foreach ($spec['gaps'] as $gap) {
            if ($gap['severity'] === $severity && $gap['subject'] === $subject && ($text === '' || str_contains($gap['message'], $text))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function part(array $spec, string $id): array
    {
        foreach ($spec['geometry']['parts'] as $part) {
            if ($part['id'] === $id) {
                return $part;
            }
        }

        self::fail("Không có bộ phận {$id}");
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        return [
            'parts' => [
                ['id' => 'N1_slot', 'op' => 'cut', 'innovation' => 'N1', 'registry' => 'superstructure', 'importance' => 'identity',
                    'params' => ['from_x' => ['landmark' => 'slot_fwd', 'axis' => 'x'], 'to_x' => ['landmark' => 'slot_aft', 'axis' => 'x'],
                        'width' => ['fact' => 'dimensions.slot_width'], 'floor_z' => ['landmark' => 'slot_fwd', 'axis' => 'z']]],
                ['id' => 'N2_facets', 'op' => 'panel', 'innovation' => 'N2', 'registry' => 'superstructure', 'importance' => 'identity', 'facing' => 'up',
                    'display_state' => 'OL:mirror_tracking', 'instances' => ['mirror_port', 'mirror_starboard'],
                    'params' => ['size_x' => ['fact' => 'data.yacht_bible.innovations.1.geometry#q1', 'pick' => 1], 'size_y' => ['fact' => 'data.yacht_bible.innovations.1.geometry#q1', 'pick' => 0],
                        'recess' => ['value' => 0.3, 'unit' => 'm', 'note' => 'sau tấm che']]],
                ['id' => 'keel_blocks', 'op' => 'keel_blocks', 'registry' => 'keel_blocks',
                    'params' => ['spacing' => ['value' => 6, 'unit' => 'm', 'note' => 'spec không ghi'], 'height' => ['value' => 1.2, 'unit' => 'm', 'note' => 'spec không ghi'], 'width' => ['value' => 2, 'unit' => 'm', 'note' => 'spec không ghi']]],
            ],
            'unused' => ['hatch' => 'nắp phẳng, không đổi hình'],
            'confirmed' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function source(?array $design = null): array
    {
        $design ??= self::design();
        $views = [];

        foreach (range(1, 8) as $i) {
            $views[] = ['id' => 'MS'.$i, 'view' => 'View '.$i, 'row' => intdiv($i - 1, 4) + 1, 'column' => ($i - 1) % 4 + 1, 'x' => (($i - 1) % 4) * 960, 'y' => intdiv($i - 1, 4) * 1080, 'width' => 960, 'height' => 1080];
        }

        $facts = (new ModelSheetFacts($design, [], $views, ['MS2_max_x' => 0.6, 'MS3_min_x' => 0.4, 'MS4_max_x' => 0.33, 'MS5_min_x' => 0.67, 'MS8_near_loa' => 0.1]))->snapshot();
        $ids = ['project_id' => 'p', 'master_image_id' => 'i', 'master_artifact_id' => 'a', 'master_sha256' => str_repeat('b', 64), 'review_stage_id' => 'r', 'set_id' => 's', 'set_execution' => 'model-sheet-master-v2',
            'facts_sha256' => str_repeat('c', 64), 'qa_stage_id' => 'q', 'approval_stage_id' => 'o', 'prompt_sha256' => str_repeat('d', 64), 'design_stage_id' => 'd', 'canonical_hash' => 'sha256:x',
            'crops' => array_map(static fn (array $v): array => ['view_id' => $v['id'], 'sha256' => str_repeat('f', 64)], $views)];

        return [
            'ids' => $ids,
            'source_sha256' => str_repeat('e', 64),
            'eligible' => true,
            'problems' => [],
            'canonical' => $design['data']['canonical'],
            'facts' => $facts,
            'views' => $views,
            'qa' => [
                'display_state' => [['part_id' => 'OL:mirror_tracking', 'part' => 'Mirror facets', 'state' => 'Parked flat.', 'refs' => [], 'numbers' => []]],
                'ms8_target' => ['innovation' => 'N2', 'entities' => ['L:mirror_port', 'L:mirror_starboard'], 'refs' => []],
            ],
            'prompt' => 'prompt',
            'crops' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        // Goc nhu config video.superyacht.model_sheet.frames (MS8 ghi so thay cho G9).
        $frames = [
            'MS1' => ['projection' => 'orthographic', 'azimuth' => 90, 'elevation' => 0, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
            'MS2' => ['projection' => 'perspective', 'azimuth' => 35, 'eye_height' => 'G7', 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
            'MS3' => ['projection' => 'perspective', 'azimuth' => 215, 'eye_height' => 'G7', 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
            'MS4' => ['projection' => 'orthographic', 'azimuth' => 0, 'elevation' => 0, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
            'MS5' => ['projection' => 'orthographic', 'azimuth' => 180, 'elevation' => 0, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
            'MS6' => ['projection' => 'orthographic', 'azimuth' => 0, 'elevation' => 90, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
            'MS7' => ['projection' => 'perspective', 'azimuth' => 35, 'elevation' => 30, 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
            'MS8' => ['projection' => 'perspective', 'azimuth' => 135, 'elevation' => 30, 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'n1'],
        ];

        return [
            'assumptions' => [
                'G1' => ['label' => 'Mái', 'value' => 2.5, 'unit' => 'số mũ'],
                'G2' => ['label' => 'Thân dưới', 'value' => 6, 'unit' => 'm'],
                'G3' => ['label' => 'Dưới nước', 'value' => [0.047, 0.10, 0.90], 'unit' => 'phần LOA'],
                'G4' => ['label' => 'Bề rộng đoạn bo', 'value' => 0.05, 'unit' => 'phần LOA'],
                'G7' => ['label' => 'Eye level', 'value' => 10, 'unit' => 'm'],
                'G8' => ['label' => 'Thành terrace', 'value' => 1, 'unit' => 'm'],
                'G11' => ['label' => 'Mũi loe', 'value' => [0.55, 0.25], 'unit' => 'tỉ lệ ; phần LOA'],
            ],
            'frames' => $frames,
            'registry' => ['keel_blocks', 'hull_shell', 'main_deck', 'superstructure', 'glazing', 'terraces', 'scaffolding_on_hull'],
            'tolerances' => ['profile_m' => 0.05, 'plan_m' => 0.05, 'deck_m' => 0.3],
        ];
    }

    /** @return array<string, mixed> */
    private static function design(): array
    {
        return ['data' => ['canonical' => [
            'hash' => 'sha256:x',
            'coordinate_system' => ['x' => 'fraction of LOA from bow', 'y' => 'metres from the centreline, positive port', 'z' => 'metres above the design waterline'],
            'dimensions' => ['loa' => ['value' => 100, 'unit' => 'm'], 'beam' => ['value' => 20, 'unit' => 'm'], 'draft' => ['value' => 4, 'unit' => 'm'], 'slot_width' => ['value' => 6, 'unit' => 'm']],
            'decks' => [
                ['number' => 1, 'name' => 'Main', 'floor_z_m' => 2, 'ceiling_z_m' => 5, 'setback_m' => 0, 'start_x' => 0.1, 'end_x' => 0.9],
                ['number' => 2, 'name' => 'Upper', 'floor_z_m' => 5, 'ceiling_z_m' => 8, 'setback_m' => 1, 'start_x' => 0.2, 'end_x' => 0.8,
                    'enclosed_segments' => [['start_x' => 0.2, 'end_x' => 0.4], ['start_x' => 0.6, 'end_x' => 0.8]]],
            ],
            'profile_curve' => [[0, 4], [0.3, 10], [0.5, 5.5], [0.7, 10], [1, 5]],
            'plan_outline' => [[0, 0], [0.5, 10], [1, 6], [1, -6], [0.5, -10]],
            'landmarks' => [
                'bow_tip' => ['x' => 0, 'z' => 4],
                'slot_fwd' => ['x' => 0.4, 'z' => 5],
                'slot_aft' => ['x' => 0.6, 'z' => 5],
                'mirror_port' => ['x' => 0.3, 'y' => 3, 'z' => 9.5],
                'mirror_starboard' => ['x' => 0.3, 'y' => -3, 'z' => 9.5],
                'hatch' => ['x' => 0.9, 'z' => 3],
            ],
            'build_states' => [
                ['id' => 'BS05', 'kind' => 'scale_model', 'present_parts' => ['hull_shell'], 'absent_parts' => ['keel_blocks', 'superstructure']],
                ['id' => 'BS07', 'kind' => 'build', 'present_parts' => ['hull_shell', 'keel_blocks'], 'absent_parts' => ['superstructure']],
                ['id' => 'BS08', 'kind' => 'build', 'present_parts' => ['hull_shell', 'superstructure'], 'absent_parts' => ['keel_blocks']],
            ],
        ], 'yacht_bible' => [
            'innovations' => [
                ['id' => 'N1', 'name' => 'Slot', 'position' => 'A longitudinal slot from x=0.4 to x=0.6.'],
                ['id' => 'N2', 'name' => 'Mirrors', 'position' => 'Recessed near the crest.', 'geometry' => 'Flat facets approximately 2 by 3 m.'],
            ],
        ]]];
    }
}
