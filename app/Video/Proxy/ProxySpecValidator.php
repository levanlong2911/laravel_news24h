<?php

declare(strict_types=1);

namespace App\Video\Proxy;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Kiem mot ProxySpec da bien dich (hoac da luu, doc lai): cau truc bang Opis, roi nghiep vu —
 * hash, nguon, don vi/pham vi theo than tau, quan he giua bo phan, man cua tung moc, build
 * state, camera va tinh nhat quan cua trang thai `ready`. Khong sua spec.
 */
final class ProxySpecValidator
{
    public const VERSION = 'proxy-spec-validator-v1';

    private const MAX_ERRORS = 200;

    private const MATRIX = [[1, 0, 0, 0], [0, 0, 1, 0], [0, 1, 0, 0], [0, 0, 0, 1]];

    /** Sai so vi tri so voi than tau (m). */
    private const MARGIN = 0.5;

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['version', 'source', 'coordinates', 'geometry', 'display_state', 'build_states', 'cameras', 'innovations', 'identity', 'assumptions', 'gaps', 'versions', 'ready', 'spec_sha256'],
        'properties' => [
            'version' => ['const' => ProxySpecCompiler::VERSION],
            'source' => [
                'type' => 'object',
                'required' => ['project_id', 'master_image_id', 'master_artifact_id', 'master_sha256', 'review_stage_id', 'set_id', 'facts_sha256', 'prompt_sha256', 'design_stage_id', 'canonical_hash', 'crops', 'source_sha256', 'eligible'],
                'properties' => [
                    'master_sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    'source_sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                    'crops' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['view_id', 'sha256']]],
                    'eligible' => ['type' => 'boolean'],
                ],
            ],
            'coordinates' => [
                'type' => 'object',
                'required' => ['loa_m', 'to_gltf'],
                'properties' => ['loa_m' => ['type' => 'number'], 'to_gltf' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'number']]]],
            ],
            'geometry' => [
                'type' => 'object',
                'required' => ['hull', 'decks', 'deck_count', 'landmarks', 'parts'],
                'properties' => [
                    'hull' => [
                        'type' => 'object',
                        'required' => ['loa', 'beam', 'draft', 'profile', 'plan_port', 'symmetry', 'shape'],
                        'properties' => [
                            'loa' => ['type' => 'number'],
                            'beam' => ['type' => ['number', 'null']],
                            'draft' => ['type' => ['number', 'null']],
                            'profile' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/point']],
                            'plan_port' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/point']],
                            'symmetry' => ['enum' => ['design', 'assumed', 'asymmetric']],
                            'shape' => ['type' => 'object', 'additionalProperties' => ['$ref' => '#/$defs/param']],
                        ],
                    ],
                    'decks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['name', 'floor', 'ceiling', 'setback', 'segments', 'outline', 'envelope_conflicts'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'floor' => ['type' => 'number'],
                                'ceiling' => ['type' => 'number'],
                                'setback' => ['type' => 'number'],
                                'segments' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/$defs/point']],
                                'outline' => ['enum' => ['x_range', 'polygon']],
                                'envelope_conflicts' => ['type' => 'integer', 'minimum' => 0],
                            ],
                        ],
                    ],
                    'deck_count' => ['type' => 'integer'],
                    'landmarks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'name', 'X', 'y', 'z', 'side', 'role', 'source'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'X' => ['type' => ['number', 'null']],
                                'y' => ['type' => ['number', 'null']],
                                'z' => ['type' => ['number', 'null']],
                                'side' => ['type' => 'string'],
                                'role' => ['enum' => [null, 'profile']],
                            ],
                        ],
                    ],
                    'parts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'op', 'label', 'registry', 'origin', 'importance', 'params', 'instances'],
                            'properties' => [
                                'id' => ['type' => 'string', 'minLength' => 1],
                                'op' => ['type' => 'string', 'minLength' => 1],
                                'registry' => ['type' => ['string', 'null']],
                                'origin' => ['enum' => ['rule', 'mapping']],
                                'importance' => ['enum' => ProxySpecCompiler::IMPORTANCE],
                                // Mang rong la object rong sau json_encode cua PHP.
                                'params' => ['type' => ['object', 'array'], 'maxItems' => 0, 'additionalProperties' => ['$ref' => '#/$defs/param']],
                                'instances' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['landmark', 'X', 'y', 'z', 'side'],
                                        'properties' => [
                                            'landmark' => ['type' => 'string', 'minLength' => 1],
                                            'X' => ['type' => ['number', 'null']],
                                            'y' => ['type' => ['number', 'null']],
                                            'z' => ['type' => ['number', 'null']],
                                            'side' => ['type' => 'string'],
                                            'mirrored' => ['type' => 'boolean'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'display_state' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['part_id']]],
            'innovations' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'name'], 'properties' => ['id' => ['type' => 'string', 'minLength' => 1]]]],
            'build_states' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['id', 'kind', 'present', 'absent', 'parts', 'missing', 'scale', 'scale_basis'],
                    'properties' => [
                        'id' => ['type' => 'string', 'minLength' => 1],
                        'kind' => ['type' => 'string'],
                        'scale' => ['type' => ['number', 'null']],
                        'scale_basis' => ['enum' => [null, 'design', 'assumption']],
                        'present' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'absent' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'parts' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'missing' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'cameras' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['view', 'projection', 'width', 'height', 'target', 'status', 'source'],
                    'properties' => [
                        'view' => ['type' => 'string', 'minLength' => 1],
                        'projection' => ['enum' => ['orthographic', 'perspective']],
                        'width' => ['type' => 'integer', 'minimum' => 1],
                        'height' => ['type' => 'integer', 'minimum' => 1],
                        'target' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['enum' => ['yacht', 'entities']], 'landmarks' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                        'status' => ['enum' => ['nominal', 'nominal_assumed', 'calibrated']],
                    ],
                ],
            ],
            'assumptions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['id', 'label', 'unit', 'affects', 'used_by', 'requires_confirmation', 'confirmed'],
                    'properties' => [
                        'id' => ['type' => 'string', 'minLength' => 1],
                        'unit' => ['type' => ['string', 'null']],
                        'used_by' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'requires_confirmation' => ['type' => 'boolean'],
                        'confirmed' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'gaps' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['severity', 'scope', 'subject', 'message'], 'properties' => ['severity' => ['enum' => ['blocking', 'warning']]]]],
            'ready' => ['type' => 'boolean'],
            'spec_sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
        ],
        '$defs' => [
            'point' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 2, 'items' => ['type' => 'number']],
            'param' => [
                'type' => 'object',
                'required' => ['value', 'basis', 'source'],
                'properties' => [
                    'value' => ['type' => ['number', 'null', 'array']],
                    'basis' => ['enum' => ['design', 'approximate', 'assumption', 'missing', 'invalid']],
                ],
            ],
        ],
    ];

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string> loi (rong = dat)
     */
    public function errors(array $spec): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(self::MAX_ERRORS);
        $result = $validator->validate(json_decode((string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false), json_decode((string) json_encode(self::SCHEMA, JSON_UNESCAPED_SLASHES), false));

        if (! $result->isValid()) {
            $errors = [];

            foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = ($pointer === '' ? '(gốc)' : $pointer).': '.$message;
                }
            }

            return $errors;
        }

        $errors = [];
        $error = static function (string $message) use (&$errors): void {
            $errors[] = $message;
        };

        if (! hash_equals((string) $spec['spec_sha256'], ProxySpecCompiler::sha256($spec))) {
            $error('spec_sha256 không khớp nội dung — spec đã bị sửa sau khi biên dịch.');
        }

        if ($spec['coordinates']['to_gltf'] !== self::MATRIX) {
            $error('Ma trận chuyển sang glTF khác quy ước (X, y, z) → (X, z, y).');
        }

        $hull = $spec['geometry']['hull'];
        $loa = (float) $spec['coordinates']['loa_m'];
        $half = is_numeric($hull['beam'] ?? null) ? (float) $hull['beam'] / 2 : null;
        $draft = is_numeric($hull['draft'] ?? null) ? (float) $hull['draft'] : 0.0;
        $profile = (array) $hull['profile'];

        if ($loa <= 0 || abs((float) $hull['loa'] - $loa) > 1e-6) {
            $error('LOA của spec không hợp lệ hoặc khác LOA thân tàu.');
        }

        $this->points($profile, 'profile', $loa, $error);
        $this->points((array) $hull['plan_port'], 'plan_port', $loa, $error);
        $top = $profile === [] ? 0.0 : max(array_column($profile, 1));

        foreach ((array) $hull['plan_port'] as [, $y]) {
            if ($y < 0 || ($half !== null && $y > $half + self::MARGIN)) {
                $error("plan_port có y = {$y} ngoài 0…nửa bề rộng.");
            }
        }

        $decks = [];

        foreach ($spec['geometry']['decks'] as $deck) {
            $decks[$deck['name']] = $deck;

            if ($deck['floor'] >= $deck['ceiling']) {
                $error("Boong {$deck['name']}: sàn không thấp hơn trần.");
            }

            $previous = -INF;

            foreach ($deck['segments'] as [$from, $to]) {
                if ($from < -self::MARGIN || $to > $loa + self::MARGIN || $from >= $to || $from < $previous) {
                    $error("Boong {$deck['name']}: đoạn [{$from}, {$to}] ngoài thân tàu, ngược chiều hoặc chồng đoạn trước.");
                }

                $previous = $to;
            }
        }

        if ($spec['geometry']['deck_count'] !== count($spec['geometry']['decks'])) {
            $error('deck_count khác số boong đã biên dịch.');
        }

        $landmarks = [];

        foreach ($spec['geometry']['landmarks'] as $mark) {
            $landmarks[$mark['name']] = $mark;

            if ($mark['X'] !== null && ($mark['X'] < -self::MARGIN || $mark['X'] > $loa + self::MARGIN)) {
                $error("Mốc {$mark['name']}: X = {$mark['X']} ngoài chiều dài tàu.");
            }

            if ($mark['y'] !== null && $mark['side'] !== self::side((float) $mark['y'])) {
                $error("Mốc {$mark['name']}: mạn {$mark['side']} trái dấu y = {$mark['y']}.");
            }
        }

        $parts = [];

        foreach ($spec['geometry']['parts'] as $part) {
            if (isset($parts[$part['id']])) {
                $error("Trùng mã bộ phận {$part['id']}.");
            }

            $parts[$part['id']] = $part;
        }

        foreach ($parts as $id => $part) {
            if ($part['op'] !== 'base' && ! isset(ProxySpecCompiler::OPS[$part['op']])) {
                $error("{$id}: phép dựng {$part['op']} không hỗ trợ.");

                continue;
            }

            $value = static fn (string $name): ?float => is_int($part['params'][$name]['value'] ?? null) || is_float($part['params'][$name]['value'] ?? null) ? (float) $part['params'][$name]['value'] : null;

            foreach ((array) $part['params'] as $name => $param) {
                $v = $param['value'];

                if ($v === null) {
                    continue;
                }

                if (! is_int($v) && ! is_float($v)) {
                    $error("{$id}.{$name}: giá trị không phải số.");
                } elseif (str_ends_with((string) $name, '_x') && ($v < -self::MARGIN || $v > $loa + self::MARGIN)) {
                    $error("{$id}.{$name} = {$v} m ngoài chiều dài tàu.");
                } elseif (in_array($name, ['width', 'length', 'diameter', 'size_x', 'size_y', 'thickness', 'pitch', 'spacing', 'height', 'rim', 'rail', 'tread', 'offset', 'pedestal'], true) && $v <= 0) {
                    $error("{$id}.{$name} phải dương.");
                } elseif (in_array($name, ['depth', 'recess', 'radius', 'wall', 'marker'], true) && $v < 0) {
                    $error("{$id}.{$name} không được âm.");
                } elseif ($half !== null && in_array($name, ['width', 'size_y'], true) && $v > 2 * $half + self::MARGIN) {
                    $error("{$id}.{$name} = {$v} m rộng hơn bề rộng tàu.");
                } elseif (str_ends_with((string) $name, '_z') && ($v < -$draft - self::MARGIN || $v > $top + self::MARGIN * 10)) {
                    $error("{$id}.{$name} = {$v} m ngoài khoảng đáy tàu…đỉnh profile.");
                }
            }

            [$from, $to] = [$value('from_x'), $value('to_x')];

            if ($from !== null && $to !== null && $from >= $to) {
                $error("{$id}: from_x phải nhỏ hơn to_x.");
            }

            // Phep khoet toi san: san phai thap hon duong bao trong pham vi, khong thi phep khoet
            // khong cat gi (khe/kenh bi lap).
            if ($part['op'] === 'cut' && $from !== null && $to !== null && ($floor = $value('floor_z')) !== null) {
                $peak = max(array_map(static fn (int $k): float => ProxySpecCompiler::interpolate($profile, $from + ($to - $from) * $k / 10), range(0, 10)));

                if ($floor >= $peak) {
                    $error("{$id}: sàn khoét {$floor} m không thấp hơn đường bao trong phạm vi — phép khoét không cắt vào vỏ.");
                }
            }

            if ($part['op'] === 'bridge' && ($X = $value('center_x')) !== null && ($topZ = $value('top_z')) !== null && $topZ > ProxySpecCompiler::interpolate($profile, $X) + self::MARGIN) {
                $error("{$id}: mặt cầu {$topZ} m cao hơn đường bao tại X {$X} m.");
            }

            foreach (['of', 'spans'] as $link) {
                if (($part[$link] ?? null) !== null && ! isset($parts[$part[$link]])) {
                    $error("{$id}.{$link}: không có bộ phận {$part[$link]}.");
                }
            }

            if ($part['op'] === 'window' && ($part['deck'] ?? null) !== null && ! isset($decks[$part['deck']])) {
                $error("{$id}: boong {$part['deck']} không có trong thiết kế.");
            }

            foreach ($part['instances'] as $instance) {
                $mark = $landmarks[$instance['landmark']] ?? null;

                if ($mark === null) {
                    $error("{$id}: mốc đặt {$instance['landmark']} không có trong spec.");

                    continue;
                }

                // Vi tri dat lay nguyen tu moc: X va z luon trung moc; y trung moc, hoac la ban doi
                // xung (-y, co gia dinh mirror), hoac 0 khi moc thieu y va co gia dinh centreline.
                // Khong co truong offset: lech vi tri nao cung la loi.
                foreach (['X', 'z'] as $axis) {
                    if ($instance[$axis] !== $mark[$axis] && ($instance[$axis] === null || $mark[$axis] === null || abs((float) $instance[$axis] - (float) $mark[$axis]) > 1e-6)) {
                        $error("{$id}: {$axis} của mốc {$instance['landmark']} khác nguồn ({$instance[$axis]} so với {$mark[$axis]}).");
                    }
                }

                if ($instance['y'] === null) {
                    continue;
                }

                $mirrored = ($instance['mirrored'] ?? false) === true;
                $centred = $mark['y'] === null;

                // Moi nhan man phai khop y, ke ca `centre` (chi khi y = 0).
                if ($instance['side'] !== self::side((float) $instance['y'])) {
                    $error("{$id}: mốc {$instance['landmark']} ghi mạn {$instance['side']} nhưng y = {$instance['y']}.");
                }

                if ($centred && ($mirrored || abs((float) $instance['y']) > 1e-9 || $instance['side'] !== 'centre' || ! $this->assumed($spec, "{$id}.centreline"))) {
                    $error("{$id}: mốc {$instance['landmark']} không có y — chỉ được đặt y = 0 trên trục giữa kèm giả định {$id}.centreline.");
                } elseif (! $centred && abs((float) $instance['y'] - ($mirrored ? -1 : 1) * (float) $mark['y']) > 1e-6) {
                    $error("{$id}: y của mốc {$instance['landmark']} khác nguồn".($mirrored ? ' (bản đối xứng)' : '').'.');
                }

                if ($mirrored && ! $this->assumed($spec, "{$id}.mirror")) {
                    $error("{$id}: có bản đối xứng nhưng không có giả định {$id}.mirror.");
                }
            }
        }

        // Spec chua du du lieu duoc xem truoc, nhung khong duoc ghi ready: moi tham so bat buoc cua
        // tung phep phai co so, moi moc dat phai du toa do, than tau du diem.
        if ($spec['ready']) {
            if (count($profile) < 3 || count((array) $hull['plan_port']) < 3) {
                $error('Spec ghi ready nhưng profile hoặc plan_port chưa đủ 3 điểm.');
            }

            foreach ($parts as $id => $part) {
                $op = ProxySpecCompiler::OPS[$part['op']] ?? null;

                if ($op === null || $part['op'] === 'omit') {
                    continue;
                }

                foreach ($op['required'] as $name) {
                    $param = $part['params'][$name] ?? null;

                    if (! is_array($param) || (! is_int($param['value']) && ! is_float($param['value'])) || in_array($param['basis'], ['missing', 'invalid'], true)) {
                        $error("Spec ghi ready nhưng {$id}.{$name} không có giá trị số hợp lệ.");
                    }
                }

                $band = $part['op'] === 'window' && isset($part['params']['from_x'], $part['params']['to_x']);

                if (($op['instances'] ?? false) === true && $part['instances'] === [] && ! $band) {
                    $error("Spec ghi ready nhưng {$id} không có mốc đặt.");
                }

                foreach ($part['instances'] as $instance) {
                    if ($instance['X'] === null || $instance['y'] === null || $instance['z'] === null) {
                        $error("Spec ghi ready nhưng mốc {$instance['landmark']} của {$id} thiếu toạ độ.");
                    }
                }
            }
        }

        foreach ($spec['build_states'] as $state) {
            foreach ($state['parts'] as $id) {
                if (! isset($parts[$id])) {
                    $error("{$state['id']}: bộ phận {$id} không có trong spec.");
                }
            }
        }

        // Bo camera theo hop dong cua master: dung cac goc co o cat (MS1..MS8), khong trung, khong
        // goc ngoai. Thieu goc chi duoc khi spec chua ready (bien dich da ghi gap).
        $contract = array_column($spec['source']['crops'], 'view_id');
        $views = [];

        foreach ($spec['cameras'] as $camera) {
            if (isset($views[$camera['view']]) || $camera['width'] <= 0 || $camera['height'] <= 0) {
                $error("Camera {$camera['view']} trùng hoặc khung ảnh không hợp lệ.");
            }

            if (! in_array($camera['view'], $contract, true)) {
                $error("Camera {$camera['view']} không thuộc các góc của model sheet master.");
            }

            $views[$camera['view']] = true;

            foreach ((array) ($camera['target']['landmarks'] ?? []) as $name) {
                if (! isset($landmarks[$name])) {
                    $error("Camera {$camera['view']}: mốc mục tiêu {$name} không có trong spec.");
                }
            }
        }

        if ($spec['ready'] && ($contract === [] || array_column($spec['cameras'], 'view') !== $contract)) {
            $error('Spec ghi ready nhưng bộ camera không đủ đúng thứ tự các góc của master ('.implode(', ', $contract).').');
        }

        // Quy tac cam bo cau truc nhan dang kiem lai tren spec da luu: hash chi chung minh noi dung
        // khop hash, khong chung minh dung nghiep vu.
        foreach ($parts as $id => $part) {
            if ($part['op'] === 'omit' && (($part['innovation'] ?? null) !== null || in_array($part['importance'], ['identity', 'silhouette'], true))) {
                $error("{$id}: không được bỏ (omit) bộ phận của đổi mới hoặc bộ phận nhận dạng/silhouette.");
            }
        }

        if ($spec['ready']) {
            foreach ($spec['innovations'] as $innovation) {
                if (array_filter($parts, static fn (array $p): bool => ($p['innovation'] ?? null) === $innovation['id'] && $p['op'] !== 'omit') === []) {
                    $error("Spec ghi ready nhưng đổi mới {$innovation['id']} không có bộ phận dựng.");
                }
            }

            foreach ($spec['build_states'] as $state) {
                $scale = $state['scale'] ?? null;

                if ($state['kind'] === 'scale_model' && ((! is_int($scale) && ! is_float($scale)) || $scale <= 0
                    || (($state['scale_basis'] ?? null) === 'assumption' && ! $this->assumed($spec, "{$state['id']}.scale", true)))) {
                    $error("Spec ghi ready nhưng {$state['id']} (mô hình tỉ lệ) chưa có tỉ lệ từ thiết kế hoặc giả định đã xác nhận.");
                }
            }
        }

        $this->bindings($spec, $parts, $loa, $error);

        $blocking = in_array('blocking', array_column($spec['gaps'], 'severity'), true);
        $pending = array_filter($spec['assumptions'], static fn (array $a): bool => $a['requires_confirmation'] && ! $a['confirmed']);

        if ($spec['ready'] && ($blocking || $pending !== [] || ! $spec['source']['eligible'])) {
            $error('Spec ghi ready nhưng còn chỗ thiếu chặn, giả định chưa xác nhận hoặc nguồn không đủ điều kiện.');
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $points
     * @param  callable(string): void  $error
     */
    private function points(array $points, string $name, float $loa, callable $error): void
    {
        $previous = -INF;

        foreach ($points as $point) {
            if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                $error("{$name}: điểm không phải [X, giá trị].");

                return;
            }

            if ($point[0] < -self::MARGIN || $point[0] > $loa + self::MARGIN || $point[0] < $previous) {
                $error("{$name}: X = {$point[0]} ngoài chiều dài tàu hoặc không tăng dần.");

                return;
            }

            $previous = (float) $point[0];
        }
    }

    /**
     * Moi gia tri khong den tu so lieu thiet ke phai trung DUNG gia tri cua gia dinh tuong ung
     * (gia tri luc xac nhan): doi gia tri ma giu xac nhan cu la loi, phai xac nhan lai.
     * - tham so nhap tay / so trich dan: gia dinh "{bo phan}.{tham so}";
     * - tham so lay ma config "G6.1": gia dinh G6, phan tu 1 (doi "phần LOA" thi nhan LOA);
     * - hinh than (hull.shape) va ti le mo hinh gia dinh (build state).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, array<string, mixed>>  $parts
     * @param  callable(string): void  $error
     */
    private function bindings(array $spec, array $parts, float $loa, callable $error): void
    {
        $assumptions = array_column($spec['assumptions'], null, 'id');
        $same = ProxySpecCompiler::same(...);

        foreach ($parts as $id => $part) {
            foreach ((array) $part['params'] as $name => $param) {
                $source = is_string($param['source']) ? $param['source'] : '';

                if ($param['value'] === null || ! in_array($param['basis'], ['assumption', 'approximate'], true)) {
                    continue;
                }

                if (str_starts_with($source, 'config:')) {
                    // Quy doi lai theo don vi da ghi cua gia dinh, cung ham voi compiler: chi mot
                    // gia tri dung (m giu nguyen, "phần LOA" nhan LOA…), khong nhan ca hai.
                    [$key, $index] = array_pad(explode('.', substr($source, 7), 2), 2, null);
                    $raw = $assumptions[$key]['value'] ?? null;
                    $raw = is_array($raw) ? ($index === null ? null : ($raw[(int) $index] ?? null)) : ($index === null ? $raw : null);
                    $expected = is_string($assumptions[$key]['unit'] ?? null)
                        ? ProxySpecCompiler::convertCode($raw, $assumptions[$key]['unit'], $index, ProxySpecCompiler::kindOf((string) $name), $loa)
                        : null;
                    $ok = $expected !== null && $same($param['value'], $expected);
                    $label = "giả định {$key}";
                } else {
                    $ok = isset($assumptions["{$id}.{$name}"]) && $same($param['value'], $assumptions["{$id}.{$name}"]['value']);
                    $label = "giả định {$id}.{$name}";
                }

                if (! $ok) {
                    $error("{$id}.{$name} = ".json_encode($param['value'])." không trùng giá trị của {$label} — đổi giá trị phải xác nhận lại.");
                }
            }
        }

        foreach ((array) $spec['geometry']['hull']['shape'] as $code => $param) {
            if (! isset($assumptions[$code]) || ! $same($param['value'], $assumptions[$code]['value'])) {
                $error("Hình thân {$code} không trùng giá trị của giả định {$code}.");
            }
        }

        foreach ($spec['build_states'] as $state) {
            if (($state['scale_basis'] ?? null) === 'assumption'
                && (! isset($assumptions["{$state['id']}.scale"]) || ! $same($state['scale'], $assumptions["{$state['id']}.scale"]['value']))) {
                $error("Tỉ lệ {$state['id']} = ".json_encode($state['scale'])." không trùng giá trị của giả định {$state['id']}.scale — đổi tỉ lệ phải xác nhận lại.");
            }
        }
    }

    /** @param array<string, mixed> $spec */
    private function assumed(array $spec, string $id, bool $confirmed = false): bool
    {
        foreach ($spec['assumptions'] as $assumption) {
            if ($assumption['id'] === $id) {
                return ! $confirmed || $assumption['confirmed'] === true;
            }
        }

        return false;
    }

    private static function side(float $y): string
    {
        return $y > 1e-9 ? 'port' : ($y < -1e-9 ? 'starboard' : 'centre');
    }
}
