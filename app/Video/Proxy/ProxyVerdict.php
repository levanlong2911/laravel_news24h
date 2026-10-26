<?php

declare(strict_types=1);

namespace App\Video\Proxy;

/**
 * Cong duyet proxy (tinh lai o server, khong tin ket luan cua client). Yeu cau suy tu SPEC, khong
 * tu bao cao: bao cao thieu muc nao la loi, khong phai bo qua.
 *
 * - ProxySpec du dieu kien va hop le.
 * - Du cac phep do bat buoc (requiredChecks) va tat ca dat; GLB doc lai khop.
 * - Du moi build state cua spec, an bo phan dung.
 * - Moi goc doi chieu co camera da xac nhan (dung hash thong so camera cua spec).
 * - MS1–MS7 co mask tham chieu da xac nhan va IoU >= nguong.
 * - Moc bat buoc nam trong khung cua tung goc phai duoc do (thay) hoac giai trinh (bi che / ngoai
 *   khung); moc do duoc phai trong gioi han % LOA. MS8 khop bang moc (can >= 3 moc thay duoc).
 *
 * `pending` = con cho thao tac cua nguoi dung (xac nhan camera/mask/moc) — chua phai loi cua proxy.
 */
final class ProxyVerdict
{
    /** Khoa thong so camera tao nen hash xac nhan (khong gom trang thai/nguon hien thi). */
    private const CAMERA_KEYS = ['view', 'projection', 'azimuth_deg', 'elevation_deg', 'eye_height_m', 'focal_mm', 'width', 'height', 'target'];

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $specErrors  ProxySpecValidator::errors()
     * @param  array<string, mixed>|null  $report  report.json cua worker
     * @param  array{iou_views: list<string>, iou_min: float}  $config
     * @param  array<string, string>  $cameras  goc => hash camera da xac nhan
     * @return array{pass: bool, failed: list<string>, pending: list<string>}
     */
    public static function evaluate(array $spec, array $specErrors, ?array $report, array $config, array $cameras = []): array
    {
        $failed = [];
        $pending = [];

        if (($spec['ready'] ?? false) !== true) {
            $failed[] = 'ProxySpec chưa đủ điều kiện dựng';
        }

        foreach ($specErrors as $error) {
            $failed[] = 'ProxySpec: '.$error;
        }

        if ($report === null) {
            $failed[] = 'chưa dựng';

            return ['pass' => false, 'failed' => $failed, 'pending' => $pending];
        }

        $checks = array_column((array) ($report['checks'] ?? []), null, 'key');

        foreach (self::requiredChecks($spec) as $key) {
            if (! isset($checks[$key])) {
                $failed[] = 'thiếu phép đo bắt buộc '.$key;
            }
        }

        foreach ($checks as $check) {
            if (($check['pass'] ?? false) !== true) {
                $failed[] = $check['key'].': '.$check['measured'].' (ngưỡng '.$check['tolerance'].')';
            }
        }

        foreach ((array) ($report['missing'] ?? []) as $missing) {
            $failed[] = 'thiếu: '.$missing;
        }

        if (($report['glb']['ok'] ?? false) !== true) {
            $failed[] = 'GLB đọc lại không khớp geometry đã dựng';
        }

        $states = array_column((array) ($report['states'] ?? []), null, 'id');

        foreach ((array) ($spec['build_states'] ?? []) as $state) {
            $got = $states[$state['id']] ?? null;

            if ($got === null) {
                $failed[] = $state['id'].': báo cáo không có build state này';
            } elseif (($got['hidden_ok'] ?? false) !== true) {
                $failed[] = $state['id'].': bộ phận vắng mặt vẫn có trong GLB ('.implode(', ', (array) ($got['leaked'] ?? [])).')';
            }
        }

        $views = (array) ($report['comparison']['views'] ?? []);
        $projected = (array) ($report['comparison']['projected'] ?? []);
        $specCameras = array_column((array) ($spec['cameras'] ?? []), null, 'view');
        $gated = array_values(array_unique([...$config['iou_views'], ...(isset($specCameras['MS8']) ? ['MS8'] : [])]));
        $required = self::requiredLandmarks($spec);

        foreach ($gated as $view) {
            $camera = $specCameras[$view] ?? null;

            if ($camera === null) {
                $failed[] = $view.': spec không có camera';

                continue;
            }

            if (($cameras[$view] ?? null) !== self::cameraSha($camera)) {
                $pending[] = $view.': camera danh nghĩa chưa được xác nhận khớp ô model sheet';
            }

            $v = $views[$view] ?? null;
            $iouView = in_array($view, $config['iou_views'], true);

            if ($iouView) {
                if ($v === null || ($v['status'] ?? null) === 'no_mask') {
                    $pending[] = $view.': chưa có mask tham chiếu đã xác nhận';
                } elseif (($v['status'] ?? null) !== 'compared') {
                    $failed[] = $view.': không đối chiếu được ('.($v['reason'] ?? $v['status'] ?? '?').')';
                } elseif (! is_numeric($v['iou'] ?? null) || (float) $v['iou'] < $config['iou_min']) {
                    $failed[] = $view.': IoU '.($v['iou'] ?? '—').' < '.$config['iou_min'];
                }
            } elseif (($v['status'] ?? null) === 'too_few_landmarks' || $v === null) {
                $pending[] = $view.': cần ≥ 3 mốc thấy được để khớp và đo';
            } elseif (($v['status'] ?? null) !== 'compared') {
                $failed[] = $view.': không đối chiếu được ('.($v['reason'] ?? $v['status'] ?? '?').')';
            }

            $recorded = array_column((array) ($v['landmarks'] ?? []), null, 'name');
            $need = $view === 'MS8' ? $required['ms8'] : $required['all'];

            // Thieu du lieu chieu khong co nghia la "khong co moc can kiem": chan.
            if (! is_array($projected[$view] ?? null)) {
                $failed[] = $view.': báo cáo thiếu dữ liệu chiếu mốc — không xác định được mốc cần kiểm';

                continue;
            }

            $unprojected = array_values(array_diff($need, array_column($projected[$view], 'name')));

            if ($unprojected !== []) {
                $failed[] = $view.': báo cáo thiếu dữ liệu chiếu của mốc '.implode(', ', $unprojected);
            }

            $inFrame = array_column(array_filter($projected[$view], static fn (array $p): bool => ($p['in_frame'] ?? false) === true), 'name');
            $missing = array_values(array_diff(array_intersect($need, $inFrame), array_keys($recorded)));

            if ($missing !== []) {
                $pending[] = $view.': chưa đo hoặc giải trình mốc '.implode(', ', $missing);
            }

            foreach ($recorded as $lm) {
                if (($lm['pass'] ?? null) === false) {
                    $failed[] = $view.': mốc '.$lm['name'].' lệch '.($lm['error_m'] ?? '—').' m > '.($lm['limit_m'] ?? '—').' m';
                }
            }
        }

        return ['pass' => $failed === [] && $pending === [], 'failed' => $failed, 'pending' => $pending];
    }

    /**
     * Phep do bat buoc cua mo hinh doi chieu (trang thai "finished" cuoi cung), suy tu spec.
     *
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    public static function requiredChecks(array $spec): array
    {
        $states = (array) ($spec['build_states'] ?? []);
        $finished = array_values(array_filter($states, static fn (array $s): bool => ($s['kind'] ?? null) === 'finished'));
        $present = $finished === [] ? null : (array) end($finished)['present'];
        $parts = array_filter((array) ($spec['geometry']['parts'] ?? []), static fn (array $p): bool => $p['op'] !== 'omit'
            && ($present === null ? ($p['registry'] ?? null) !== 'scaffolding_on_hull' : in_array($p['registry'], $present, true)));
        $keys = ['profile', 'plan', 'ms6'];

        if (is_numeric($spec['geometry']['hull']['beam'] ?? null) && $spec['geometry']['hull']['beam'] > 0) {
            $keys[] = 'ratio';
        }

        if ($present === null || in_array('superstructure', $present, true)) {
            $keys[] = 'decks';

            foreach ((array) ($spec['geometry']['landmarks'] ?? []) as $mark) {
                if (($mark['role'] ?? null) === 'profile') {
                    $keys[] = 'profile_landmark:'.$mark['name'];
                }
            }
        }

        foreach ($parts as $part) {
            $suffix = static fn (array $i): string => ($i['y'] ?? 0) < 0 ? ':s' : '';

            match ($part['op']) {
                'cut' => array_push($keys, 'void:'.$part['id'], 'cut:'.$part['id']),
                'pocket', 'basin', 'bridge', 'window', 'ribs', 'relief' => $keys[] = $part['op'].':'.$part['id'],
                'disc', 'panel' => array_push($keys, ...array_map(static fn (array $i): string => 'instance:'.$part['id'].':'.$i['landmark'].$suffix($i), $part['instances'])),
                'opening' => array_push($keys, ...array_map(static fn (array $i): string => 'opening:'.$part['id'].':'.$i['landmark'].$suffix($i), $part['instances'])),
                default => null,
            };
        }

        return array_values(array_unique($keys));
    }

    /**
     * Moc bat buoc doi chieu: moc duong bao, moc dat/tham so cua bo phan nhan dang hoac silhouette;
     * MS8: moc muc tieu cua master.
     *
     * @param  array<string, mixed>  $spec
     * @return array{all: list<string>, ms8: list<string>}
     */
    public static function requiredLandmarks(array $spec): array
    {
        $names = [];

        foreach ((array) ($spec['geometry']['landmarks'] ?? []) as $mark) {
            if (($mark['role'] ?? null) === 'profile') {
                $names[] = $mark['name'];
            }
        }

        foreach ((array) ($spec['geometry']['parts'] ?? []) as $part) {
            if (! in_array($part['importance'] ?? null, ['identity', 'silhouette'], true) || $part['op'] === 'omit') {
                continue;
            }

            foreach ((array) $part['instances'] as $instance) {
                $names[] = $instance['landmark'];
            }

            foreach ((array) $part['params'] as $param) {
                if (is_string($param['source'] ?? null) && preg_match('/^data\.canonical\.landmarks\.([^.]+)\.[xyz]$/', $param['source'], $m) === 1) {
                    $names[] = $m[1];
                }
            }
        }

        $ms8 = [];

        foreach ((array) ($spec['cameras'] ?? []) as $camera) {
            if ($camera['view'] === 'MS8') {
                $ms8 = array_values((array) ($camera['target']['landmarks'] ?? []));
            }
        }

        return ['all' => array_values(array_unique($names)), 'ms8' => $ms8];
    }

    /** @param array<string, mixed> $camera camera cua spec */
    public static function cameraSha(array $camera): string
    {
        $subset = [];

        foreach (self::CAMERA_KEYS as $key) {
            $subset[$key] = $camera[$key] ?? null;
        }

        return hash('sha256', (string) json_encode($subset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
