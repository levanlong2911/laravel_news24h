<?php

declare(strict_types=1);

namespace App\Video\ReferencePack;

use App\Video\Superyacht\Support\CanonicalJson;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * RULE va muc san sang cua bo anh tham chieu, dung manifest `ref_pack`. Thuan (khong DB).
 *
 * Ba trang thai doc lap moi muc: tao noi dung (`generation`), kiem chat luong (`quality`: RULE do
 * bang code; hinh anh `vision` = unchecked khi chua co cong Vision tin cay — khong bao dat), chap
 * nhan su dung (`acceptance`: inherited tu luot duyet goc, pending, accepted, rejected).
 *
 * San sang theo muc dung: ngoai that (views, details bat buoc, mau); noi that theo tung phong;
 * video; phong cach (sau 5L). Thieu style hay turntable khong chan ngoai that.
 */
final class ReferencePackValidator
{
    public const POLICY_VERSION = 'reference-pack-policy-v1';

    public const SCHEMA_FILE = 'ai/superyacht/contracts/ReferencePack.json';

    private const MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Canh ngan toi thieu theo nhom (px). */
    private const MIN_SHORT_SIDE = ['views' => 512, 'details' => 1024, 'colors' => 512, 'rooms' => 1024];

    /**
     * Ket qua tung muc theo RULE.
     *
     * @param  array<string, mixed>  $item  muc cua ke hoach
     * @param  ?array<string, mixed>  $result  {artifact_id, sha256, mime, width, height, file_ok, origin, acceptance, ...}
     * @return array{generation: string, rule: string, vision: string, acceptance: string, problems: list<string>}
     */
    public static function itemStatus(array $item, ?array $result): array
    {
        $method = (string) $item['method'];

        if (in_array($method, [ReferencePackCompiler::METHOD_NOT_APPLICABLE, ReferencePackCompiler::METHOD_UNSUPPORTED, ReferencePackCompiler::METHOD_PENDING, ReferencePackCompiler::METHOD_MISSING], true)) {
            return ['generation' => $method, 'rule' => $method === ReferencePackCompiler::METHOD_MISSING ? 'fail' : 'skipped', 'vision' => 'unchecked',
                'acceptance' => 'none', 'problems' => $method === ReferencePackCompiler::METHOD_MISSING ? [(string) $item['reason']] : []];
        }

        if ($result === null || ! is_string($result['artifact_id'] ?? null)) {
            return ['generation' => (string) ($result['generation'] ?? 'missing'), 'rule' => 'fail', 'vision' => 'unchecked', 'acceptance' => 'none',
                'problems' => array_values(array_filter(['Chưa có file.', $result['error'] ?? null]))];
        }

        $problems = [];
        $short = min((int) ($result['width'] ?? 0), (int) ($result['height'] ?? 0));
        $min = self::MIN_SHORT_SIDE[$item['group']] ?? 512;

        if (($result['file_ok'] ?? false) !== true) {
            $problems[] = 'File không còn hoặc không khớp sha256.';
        }

        if (! is_string($result['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $result['sha256']) !== 1) {
            $problems[] = 'Thiếu sha256 hợp lệ.';
        }

        if (! in_array((string) ($result['mime'] ?? ''), self::MIMES, true)) {
            $problems[] = 'Định dạng '.($result['mime'] ?? '?').' không hỗ trợ.';
        }

        if ($short < $min) {
            $problems[] = "Kích thước {$result['width']}×{$result['height']} nhỏ hơn cạnh ngắn tối thiểu {$min} px.";
        }

        // Loi nguon / truy vet / do mau do ben goi phat hien (vd. crop khac snapshot, mau lech HEX).
        foreach ((array) ($result['problems'] ?? []) as $problem) {
            $problems[] = (string) $problem;
        }

        return [
            'generation' => 'done',
            'rule' => $problems === [] ? 'pass' : 'fail',
            'vision' => (string) ($result['vision'] ?? 'unchecked'),
            'acceptance' => (string) ($result['acceptance'] ?? 'pending'),
            'problems' => $problems,
        ];
    }

    /**
     * Muc san sang theo muc dung.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $statuses  item_id => self::itemStatus()
     * @return array<string, array<string, mixed>>
     */
    public static function readiness(array $items, array $statuses): array
    {
        $blocking = [];
        $rooms = [];

        foreach ($items as $item) {
            $status = $statuses[$item['id']];
            $usable = $status['rule'] === 'pass' && $status['acceptance'] !== 'rejected';

            if ($item['level'] === ReferencePackCompiler::LEVEL_EXTERIOR && $item['requirement'] === ReferencePackCompiler::REQUIRED && ! $usable) {
                $blocking[] = $item['id'];
            }

            if ($item['group'] === 'rooms') {
                $rooms[$item['room']][$item['kind']] = $usable;
                $rooms[$item['room']]['missing_source'] = ($rooms[$item['room']]['missing_source'] ?? false) || $item['method'] === ReferencePackCompiler::METHOD_MISSING;
            }
        }

        $ready = array_keys(array_filter($rooms, static fn (array $r): bool => ($r['finished'] ?? false) && ($r['materials'] ?? false)));
        $turntable = array_values(array_filter($items, static fn (array $i): bool => $i['group'] === 'turntable'))[0] ?? null;

        return [
            ReferencePackCompiler::LEVEL_EXTERIOR => ['status' => $blocking === [] ? 'ready' : 'incomplete', 'blocking' => $blocking],
            ReferencePackCompiler::LEVEL_INTERIOR => [
                'status' => $rooms === [] ? 'not_applicable' : ($ready === [] ? 'pending' : (count($ready) === count($rooms) ? 'ready' : 'partial')),
                'ready_rooms' => $ready,
                'missing_source_rooms' => array_keys(array_filter($rooms, static fn (array $r): bool => $r['missing_source'])),
            ],
            ReferencePackCompiler::LEVEL_VIDEO => ['status' => $turntable === null ? 'not_applicable' : (($statuses[$turntable['id']]['rule'] ?? null) === 'pass' ? 'ready' : ($turntable['method'] === ReferencePackCompiler::METHOD_UNSUPPORTED ? 'unsupported' : 'pending')),
                'reason' => $turntable['reason'] ?? null],
            ReferencePackCompiler::LEVEL_STYLE => ['status' => 'pending_5l', 'reason' => 'Style refs bổ sung từ Phase 5L bằng revision pack mới.'],
        ];
    }

    /**
     * Manifest `ref_pack` (nhom theo dac ta: views, details, colors, rooms, turntable, style).
     *
     * @param  array<string, mixed>  $sources  snapshot nguon cua ke hoach
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public static function manifest(array $sources, array $items, array $results): array
    {
        $statuses = [];

        foreach ($items as $item) {
            $statuses[$item['id']] = self::itemStatus($item, $results[$item['id']] ?? null);
        }

        $entry = static function (array $item) use ($results, $statuses): array {
            $result = $results[$item['id']] ?? [];

            return [
                'id' => $item['id'],
                'subject' => (string) $item['subject'],
                'requirement' => (string) $item['requirement'],
                'method' => (string) $item['method'],
                'reason' => (string) $item['reason'],
                'sources' => array_values($item['sources']),
                'state' => (string) $item['state'],
                'artifact_id' => is_string($result['artifact_id'] ?? null) ? $result['artifact_id'] : null,
                'sha256' => is_string($result['sha256'] ?? null) ? $result['sha256'] : null,
                'mime' => is_string($result['mime'] ?? null) ? $result['mime'] : null,
                'width' => is_int($result['width'] ?? null) ? $result['width'] : null,
                'height' => is_int($result['height'] ?? null) ? $result['height'] : null,
                'origin' => is_string($result['origin'] ?? null) ? $result['origin'] : null,
                'evidence' => is_array($result['evidence'] ?? null) ? $result['evidence'] : [],
                'status' => $statuses[$item['id']],
            ];
        };
        $of = static fn (string $group): array => array_values(array_map($entry, array_filter($items, static fn (array $i): bool => $i['group'] === $group)));
        $rooms = [];

        foreach (array_filter($items, static fn (array $i): bool => $i['group'] === 'rooms') as $item) {
            $rooms[$item['room']][$item['kind']] = $entry($item);
        }

        $single = static fn (string $group): ?array => $of($group)[0] ?? null;
        $readiness = self::readiness($items, $statuses);
        $violations = [];

        foreach ($items as $item) {
            if ($item['requirement'] === ReferencePackCompiler::REQUIRED && $statuses[$item['id']]['rule'] === 'fail') {
                foreach ($statuses[$item['id']]['problems'] ?: ['Chưa đạt RULE.'] as $problem) {
                    $violations[] = ['item_id' => $item['id'], 'message' => $problem];
                }
            }
        }

        $pack = [
            'version' => 1,
            'policy_version' => self::POLICY_VERSION,
            'compiler_version' => ReferencePackCompiler::VERSION,
            'content_sha256' => self::contentHash($sources, $items, $results, $statuses),
            'sources' => $sources,
            'views' => $of('views'),
            'details' => $of('details'),
            'colors' => $single('colors'),
            'rooms' => (object) $rooms,
            'turntable' => $single('turntable'),
            'style' => [],
            'readiness' => $readiness,
            'validation' => ['rule' => $violations === [] ? 'pass' : 'fail', 'violations' => $violations, 'vision' => 'unchecked'],
        ];

        return $pack + ['manifest_sha256' => CanonicalJson::hash($pack)];
    }

    /**
     * Quyet dinh chap nhan ghi vao luot duyet pack: moi muc co file dat RULE va chua bi tu choi — goc
     * MS, chi tiet, mau, ca hai anh cua tung phong, turntable khi co. Selector chi dung danh sach nay.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{item_id: string, artifact_id: string, sha256: string, basis: string}>
     */
    public static function acceptedEntries(array $manifest): array
    {
        $entries = [...(array) $manifest['views'], ...(array) $manifest['details'], ...array_filter([$manifest['colors'] ?? null, $manifest['turntable'] ?? null])];

        foreach ((array) $manifest['rooms'] as $room) {
            foreach (['finished', 'materials'] as $kind) {
                if (is_array(((array) $room)[$kind] ?? null)) {
                    $entries[] = ((array) $room)[$kind];
                }
            }
        }

        $accepted = [];

        foreach ($entries as $entry) {
            if (is_string($entry['artifact_id'] ?? null) && ($entry['status']['rule'] ?? null) === 'pass' && ($entry['status']['acceptance'] ?? null) !== 'rejected') {
                $accepted[] = ['item_id' => $entry['id'], 'artifact_id' => $entry['artifact_id'], 'sha256' => (string) $entry['sha256'],
                    'basis' => ($entry['status']['acceptance'] ?? null) === 'inherited' ? 'inherited_model_sheet_review' : 'manual_pack_review'];
            }
        }

        return $accepted;
    }

    /**
     * Dau van noi dung on dinh: nguon + moi muc (method, artifact, sha256, chap nhan). Khong gom
     * evidence, ket qua do hay loi kiem — de so "bo da kiem con dung noi dung hien tai" giua lan kiem
     * sau (doc bytes) va lan mo trang (khong doc bytes). Kiem sau van chay lai khi duyet.
     *
     * @param  array<string, mixed>  $sources
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, array<string, mixed>>  $statuses
     */
    public static function contentHash(array $sources, array $items, array $results, array $statuses): string
    {
        return CanonicalJson::hash([
            'sources' => $sources,
            'items' => array_values(array_map(static fn (array $item): array => [
                'id' => $item['id'],
                'method' => $item['method'],
                'artifact_id' => is_string($results[$item['id']]['artifact_id'] ?? null) ? $results[$item['id']]['artifact_id'] : null,
                'sha256' => is_string($results[$item['id']]['sha256'] ?? null) ? $results[$item['id']]['sha256'] : null,
                'acceptance' => $statuses[$item['id']]['acceptance'],
            ], $items)),
        ]);
    }

    /**
     * Kiem manifest voi schema hop dong ReferencePack (file trong repo).
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function schemaErrors(array $manifest, ?string $file = null): array
    {
        $file ??= resource_path(self::SCHEMA_FILE);

        if (! is_file($file)) {
            throw new RuntimeException('Không tìm thấy schema ReferencePack: '.$file);
        }

        $validator = new Validator();
        $validator->setMaxErrors(20);
        $validator->resolver()->registerFile('https://superyacht.local/schemas/ReferencePack', $file);
        $result = $validator->validate(json_decode((string) json_encode($manifest)), 'https://superyacht.local/schemas/ReferencePack');

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = trim($path.' '.$message);
            }
        }

        return $errors;
    }
}
