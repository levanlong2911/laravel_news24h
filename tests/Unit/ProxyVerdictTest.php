<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Video\YachtProxyShotService;
use App\Video\Proxy\ProxyVerdict;
use PHPUnit\Framework\TestCase;

/** Cong duyet proxy: yeu cau suy tu spec, tinh lai tu bao cao worker. Thuan, khong DB. */
final class ProxyVerdictTest extends TestCase
{
    private const GATE = ['iou_views' => ['MS1', 'MS2'], 'iou_min' => 0.85];

    public function test_complete_report_with_confirmed_references_and_cameras_passes(): void
    {
        self::assertSame(['pass' => true, 'failed' => [], 'pending' => []], $this->evaluate());
    }

    public function test_required_checks_come_from_the_spec(): void
    {
        self::assertSame(['profile', 'plan', 'ms6', 'ratio', 'decks', 'profile_landmark:bow_tip', 'void:N1_slot', 'cut:N1_slot', 'instance:N2_facets:mirror_port', 'instance:N2_facets:mirror_starboard:s'], ProxyVerdict::requiredChecks($this->spec()));
    }

    public function test_ribs_and_relief_need_their_mesh_check(): void
    {
        $spec = $this->spec();
        $spec['geometry']['parts'][] = ['id' => 'R_ribs', 'op' => 'ribs', 'registry' => 'superstructure', 'importance' => 'identity', 'params' => [], 'instances' => []];
        $spec['geometry']['parts'][] = ['id' => 'R_relief', 'op' => 'relief', 'registry' => 'superstructure', 'importance' => 'identity', 'of' => 'N1_slot', 'params' => [], 'instances' => []];
        $required = ProxyVerdict::requiredChecks($spec);

        self::assertContains('ribs:R_ribs', $required);
        self::assertContains('relief:R_relief', $required);

        // Bao cao khong co phep do gan (vd. bo dung cu bo qua gan) -> khong duoc dat.
        $verdict = ProxyVerdict::evaluate($spec, [], $this->report(), ['iou_views' => ['MS1', 'MS2'], 'iou_min' => 0.85]);
        self::assertContains('thiếu phép đo bắt buộc ribs:R_ribs', $verdict['failed']);
        self::assertContains('thiếu phép đo bắt buộc relief:R_relief', $verdict['failed']);
    }

    public function test_report_without_checks_and_states_fails(): void
    {
        $report = $this->report();
        $report['checks'] = [];
        $report['states'] = [];
        $verdict = $this->evaluate(report: $report);

        self::assertFalse($verdict['pass']);
        self::assertContains('thiếu phép đo bắt buộc void:N1_slot', $verdict['failed']);
        self::assertContains('BS12: báo cáo không có build state này', $verdict['failed']);
    }

    public function test_a_single_missing_required_check_fails(): void
    {
        $report = $this->report();
        $report['checks'] = array_values(array_filter($report['checks'], static fn (array $c): bool => $c['key'] !== 'instance:N2_facets:mirror_starboard:s'));

        self::assertContains('thiếu phép đo bắt buộc instance:N2_facets:mirror_starboard:s', $this->evaluate(report: $report)['failed']);
    }

    public function test_landmarks_must_be_covered_on_every_view_not_just_one(): void
    {
        $report = $this->report();
        $report['comparison']['views']['MS2']['landmarks'] = [];
        $verdict = $this->evaluate(report: $report);

        self::assertFalse($verdict['pass']);
        self::assertContains('MS2: chưa đo hoặc giải trình mốc bow_tip, slot_fwd, mirror_port', $verdict['pending']);
    }

    public function test_missing_projection_data_blocks_instead_of_requiring_nothing(): void
    {
        $report = $this->report();
        unset($report['comparison']['projected']);
        $report['comparison']['views']['MS2']['landmarks'] = [];
        $verdict = $this->evaluate(report: $report);

        self::assertFalse($verdict['pass']);
        self::assertContains('MS2: báo cáo thiếu dữ liệu chiếu mốc — không xác định được mốc cần kiểm', $verdict['failed']);

        $partial = $this->report();
        $partial['comparison']['projected']['MS1'] = array_values(array_filter($partial['comparison']['projected']['MS1'], static fn (array $p): bool => $p['name'] !== 'slot_fwd'));
        self::assertContains('MS1: báo cáo thiếu dữ liệu chiếu của mốc slot_fwd', $this->evaluate(report: $partial)['failed']);
    }

    public function test_out_of_frame_landmarks_are_not_required_and_explained_ones_count(): void
    {
        $report = $this->report();
        $report['comparison']['projected']['MS2'][2]['in_frame'] = false;
        $report['comparison']['views']['MS2']['landmarks'] = [
            ['name' => 'bow_tip', 'status' => 'visible', 'error_m' => 0.4, 'limit_m' => 3, 'pass' => true],
            ['name' => 'slot_fwd', 'status' => 'occluded', 'error_m' => null, 'pass' => null],
        ];

        self::assertTrue($this->evaluate(report: $report)['pass']);
    }

    public function test_nominal_or_changed_camera_is_pending(): void
    {
        self::assertContains('MS1: camera danh nghĩa chưa được xác nhận khớp ô model sheet', $this->evaluate(cameras: ['MS2' => $this->cameraSha('MS2')])['pending']);

        $spec = $this->spec();
        $spec['cameras'][1]['focal_mm'] = 50.0;
        self::assertContains('MS2: camera danh nghĩa chưa được xác nhận khớp ô model sheet', $this->evaluate(spec: $spec)['pending']);
    }

    public function test_low_iou_landmark_error_and_missing_mask(): void
    {
        $report = $this->report();
        $report['comparison']['views']['MS1']['iou'] = 0.71;
        $report['comparison']['views']['MS1']['landmarks'][0] = ['name' => 'bow_tip', 'status' => 'visible', 'error_m' => 6.2, 'limit_m' => 3, 'pass' => false];
        $report['comparison']['views']['MS2'] = ['status' => 'no_mask', 'iou' => null, 'landmarks' => $report['comparison']['views']['MS2']['landmarks']];
        $verdict = $this->evaluate(report: $report);

        self::assertContains('MS1: IoU 0.71 < 0.85', $verdict['failed']);
        self::assertContains('MS1: mốc bow_tip lệch 6.2 m > 3 m', $verdict['failed']);
        self::assertContains('MS2: chưa có mask tham chiếu đã xác nhận', $verdict['pending']);
    }

    public function test_spec_not_ready_glb_and_hidden_part_fail(): void
    {
        $spec = $this->spec();
        $spec['ready'] = false;
        $report = $this->report();
        $report['glb']['ok'] = false;
        $report['states'][0] = ['id' => 'BS12', 'hidden_ok' => false, 'leaked' => ['scaffold']];
        $failed = $this->evaluate(spec: $spec, report: $report)['failed'];

        self::assertContains('ProxySpec chưa đủ điều kiện dựng', $failed);
        self::assertContains('GLB đọc lại không khớp geometry đã dựng', $failed);
        self::assertContains('BS12: bộ phận vắng mặt vẫn có trong GLB (scaffold)', $failed);
        self::assertContains('chưa dựng', ProxyVerdict::evaluate($this->spec(), [], null, self::GATE)['failed']);
    }

    public function test_shot_cache_key_changes_with_every_input(): void
    {
        $camera = ['projection' => 'perspective', 'position' => [0, 10, 50], 'target' => [70, 5, 0], 'up' => [0, 1, 0], 'fov' => 24.0, 'near' => 0.5, 'far' => 900];
        $key = YachtProxyShotService::cacheKey('S1', 'spec', 'BS08', $camera, 'builder', 1280, 720);

        self::assertSame($key, YachtProxyShotService::cacheKey('S1', 'spec', 'BS08', $camera + ['label' => 'không ảnh hưởng'], 'builder', 1280, 720));

        // Shot khac cung camera/state/kich thuoc van co khoa rieng (artifact, shot.json khong dung chung).
        foreach ([['S2', 'spec', 'BS08', $camera, 'builder', 1280, 720], ['S1', 'spec2', 'BS08', $camera, 'builder', 1280, 720], ['S1', 'spec', 'BS09', $camera, 'builder', 1280, 720], ['S1', 'spec', 'BS08', ['fov' => 30.0] + $camera, 'builder', 1280, 720], ['S1', 'spec', 'BS08', $camera, 'builder2', 1280, 720], ['S1', 'spec', 'BS08', $camera, 'builder', 1920, 1080]] as $args) {
            self::assertNotSame($key, YachtProxyShotService::cacheKey(...$args));
        }
    }

    public function test_progress_shots_in_a_group_must_share_one_camera(): void
    {
        $camera = ['projection' => 'perspective', 'position' => [0, 10, 50], 'target' => [70, 5, 0], 'up' => [0, 1, 0], 'fov' => 24.0, 'near' => 0.5, 'far' => 900];
        $shots = [['shot_id' => 'S1', 'kind' => 'PROGRESS', 'group' => 'P1', 'camera' => $camera, 'width' => 1280, 'height' => 720], ['shot_id' => 'S2', 'kind' => 'PROGRESS', 'group' => 'P1', 'camera' => $camera, 'width' => 1280, 'height' => 720]];

        self::assertNull(YachtProxyShotService::progressProblem($shots));

        // Cung ti le khung, khac do phan giai: van cung khoa.
        $sameAspect = $shots;
        [$sameAspect[1]['width'], $sameAspect[1]['height']] = [1920, 1080];
        self::assertNull(YachtProxyShotService::progressProblem($sameAspect));

        // Cung camera nhung khung doc 720x1280: goc nhin ngang khac -> chan.
        $portrait = $shots;
        [$portrait[1]['width'], $portrait[1]['height']] = [720, 1280];
        self::assertStringContainsString('S2 dùng camera hoặc tỉ lệ khung khác', (string) YachtProxyShotService::progressProblem($portrait));
        self::assertNotSame(YachtProxyShotService::cameraLockSha($camera, 1280, 720), YachtProxyShotService::cameraLockSha($camera, 720, 1280));

        $shots[1]['camera']['position'] = [0, 11, 50];
        self::assertStringContainsString('S2 dùng camera hoặc tỉ lệ khung khác', (string) YachtProxyShotService::progressProblem($shots));

        unset($shots[0]['group'], $shots[1]['group']);
        self::assertStringContainsString('phải khai nhóm', (string) YachtProxyShotService::progressProblem($shots));
    }

    public function test_shot_needs_a_full_compiled_camera_and_a_known_state(): void
    {
        $shot = ['shot_id' => 'S1', 'state' => 'BS08', 'width' => 1280, 'height' => 720, 'camera' => ['projection' => 'perspective', 'position' => [0, 1, 2], 'target' => [3, 4, 5], 'up' => [0, 1, 0], 'fov' => 24, 'near' => 0.5, 'far' => 900]];

        self::assertNull(YachtProxyShotService::shotProblem($shot, ['BS08']));
        self::assertNotNull(YachtProxyShotService::shotProblem(['state' => 'BS99'] + $shot, ['BS08']));
        self::assertNotNull(YachtProxyShotService::shotProblem(['camera' => ['projection' => 'perspective']] + $shot, ['BS08']));
    }

    public function test_shot_camera_values_are_validated_not_just_present(): void
    {
        $shot = ['shot_id' => 'S1', 'state' => 'BS08', 'width' => 1280, 'height' => 720, 'camera' => ['projection' => 'perspective', 'position' => [0, 1, 2], 'target' => [3, 4, 5], 'up' => [0, 1, 0], 'fov' => 24, 'near' => 0.5, 'far' => 900]];
        $ortho = ['camera' => ['projection' => 'orthographic', 'position' => [0, 0, 100], 'target' => [0, 0, 0], 'up' => [0, 1, 0], 'near' => 0.1, 'far' => 300, 'ortho' => ['left' => -64, 'right' => 64, 'top' => 36, 'bottom' => -36]]] + $shot;
        $with = static function (array $base, string $key, mixed $value): array {
            $base['camera'][$key] = $value;

            return $base;
        };
        $bad = [
            'projection lạ' => $with($shot, 'projection', 'fisheye'),
            'position không đủ 3 số' => $with($shot, 'position', [0, 1]),
            'target có NaN' => $with($shot, 'target', [3, NAN, 5]),
            'up là chuỗi' => $with($shot, 'up', ['0', '1', '0']),
            'position trùng target' => $with($shot, 'target', [0, 1, 2]),
            'up bằng 0' => $with($shot, 'up', [0, 0, 0]),
            'up song song hướng nhìn' => $with($shot, 'up', [3, 3, 3]),
            'near = 0' => $with($shot, 'near', 0),
            'near âm' => $with($shot, 'near', -1),
            'far ≤ near' => $with($shot, 'far', 0.5),
            'far vô hạn' => $with($shot, 'far', INF),
            'fov = 0' => $with($shot, 'fov', 0),
            'fov ≥ 180' => $with($shot, 'fov', 180),
            'fov là chuỗi' => $with($shot, 'fov', '24'),
            'ortho thiếu cạnh' => $with($ortho, 'ortho', ['left' => -64, 'right' => 64, 'top' => 36]),
            'ortho left ≥ right' => $with($ortho, 'ortho', ['left' => 64, 'right' => -64, 'top' => 36, 'bottom' => -36]),
            'ortho bottom ≥ top' => $with($ortho, 'ortho', ['left' => -64, 'right' => 64, 'top' => -36, 'bottom' => 36]),
            'ortho khác tỉ lệ ảnh' => $with($ortho, 'ortho', ['left' => -64, 'right' => 64, 'top' => 64, 'bottom' => -64]),
            'kích thước là chuỗi' => ['width' => '1280'] + $shot,
        ];

        self::assertNull(YachtProxyShotService::shotProblem($shot, ['BS08']));
        self::assertNull(YachtProxyShotService::shotProblem($ortho, ['BS08']));

        foreach ($bad as $case => $value) {
            self::assertNotNull(YachtProxyShotService::shotProblem($value, ['BS08']), $case);
        }
    }

    /** @return array{pass: bool, failed: list<string>, pending: list<string>} */
    private function evaluate(?array $spec = null, ?array $report = null, ?array $cameras = null): array
    {
        return ProxyVerdict::evaluate($spec ?? $this->spec(), [], $report ?? $this->report(), self::GATE, $cameras ?? ['MS1' => $this->cameraSha('MS1'), 'MS2' => $this->cameraSha('MS2')]);
    }

    private function cameraSha(string $view): string
    {
        return ProxyVerdict::cameraSha(collect($this->spec()['cameras'])->firstWhere('view', $view));
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $param = static fn (string $source): array => ['value' => 1.0, 'basis' => 'design', 'source' => $source];
        $camera = static fn (string $view, float $azimuth): array => ['view' => $view, 'projection' => 'orthographic', 'azimuth_deg' => $azimuth, 'elevation_deg' => 0.0, 'eye_height_m' => null, 'focal_mm' => null, 'width' => 960, 'height' => 1080, 'target' => ['kind' => 'yacht']];

        return [
            'ready' => true,
            'geometry' => [
                'hull' => ['beam' => 20.0],
                'landmarks' => [['name' => 'bow_tip', 'role' => 'profile'], ['name' => 'slot_fwd', 'role' => null], ['name' => 'mirror_port', 'role' => null], ['name' => 'hatch', 'role' => null]],
                'parts' => [
                    ['id' => 'hull', 'op' => 'base', 'registry' => 'hull_shell', 'importance' => 'silhouette', 'params' => [], 'instances' => []],
                    ['id' => 'N1_slot', 'op' => 'cut', 'registry' => 'superstructure', 'importance' => 'identity', 'params' => ['from_x' => $param('data.canonical.landmarks.slot_fwd.x'), 'width' => $param('data.canonical.dimensions.slot_width.value')], 'instances' => []],
                    ['id' => 'N2_facets', 'op' => 'panel', 'registry' => 'superstructure', 'importance' => 'identity', 'params' => [], 'instances' => [['landmark' => 'mirror_port', 'y' => 3.0], ['landmark' => 'mirror_starboard', 'y' => -3.0]]],
                    ['id' => 'scaffold', 'op' => 'scaffold', 'registry' => 'scaffolding_on_hull', 'importance' => 'detail', 'params' => [], 'instances' => []],
                ],
            ],
            'build_states' => [['id' => 'BS12', 'kind' => 'finished', 'present' => ['hull_shell', 'superstructure']]],
            'cameras' => [$camera('MS1', 90.0), $camera('MS2', 35.0)],
        ];
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $checks = array_map(static fn (string $key): array => ['key' => $key, 'pass' => true, 'measured' => 'ok', 'tolerance' => 'ok'], ProxyVerdict::requiredChecks($this->spec()));
        $marks = [['name' => 'bow_tip', 'status' => 'visible', 'error_m' => 0.4, 'limit_m' => 3, 'pass' => true], ['name' => 'slot_fwd', 'status' => 'visible', 'error_m' => 0.8, 'limit_m' => 3, 'pass' => true], ['name' => 'mirror_port', 'status' => 'occluded', 'error_m' => null, 'pass' => null]];
        $inFrame = [['name' => 'bow_tip', 'in_frame' => true], ['name' => 'slot_fwd', 'in_frame' => true], ['name' => 'mirror_port', 'in_frame' => true], ['name' => 'mirror_starboard', 'in_frame' => false], ['name' => 'hatch', 'in_frame' => true]];

        return [
            'checks' => $checks,
            'missing' => [],
            'glb' => ['ok' => true],
            'states' => [['id' => 'BS12', 'hidden_ok' => true, 'leaked' => []]],
            'comparison' => [
                'views' => ['MS1' => ['status' => 'compared', 'iou' => 0.9, 'landmarks' => $marks], 'MS2' => ['status' => 'compared', 'iou' => 0.88, 'landmarks' => $marks]],
                'projected' => ['MS1' => $inFrame, 'MS2' => $inFrame],
            ],
        ];
    }
}
