<?php

declare(strict_types=1);

namespace App\Video\ReferencePack;

/**
 * Chon reference cho mot luot goi tu manifest pack da duyet (khong gui ca pack). Thuan.
 *
 * Chi dung muc co quyet dinh chap nhan that trong luot duyet pack (`accepted`: dung item, artifact
 * va sha256) — "san sang de duyet" khong phai "duoc phep dung". Muc dat RULE ma chua duoc duyet,
 * hay da duoc duyet voi file khac, deu khong dung duoc.
 *
 * Dau ra phan vai: anh edit, hinh dang / nhan dang, chi tiet, vat lieu. Gioi han anh lay tu tool
 * profile cua luot goi (tinh ca anh edit); khong am tham bo reference bat buoc de vua gioi han —
 * khong du cho thi tra ly do.
 *
 * Shot thi cong (build state chua hoan thien): pack chi co anh hoan thien, nen MS chi duoc gan vai
 * `shape_only` (giu hinh dang, doi trang thai) va khong bao gio lam anh edit; chi tiet, mau, noi that
 * hoan thien bi loai. Anh edit phai la nguon dung trang thai thi cong (`state_source`) DA DUOC XAC
 * MINH o lop service (artifact cua du an, file/hash, bang chung chap nhan, build state) — selector
 * khong tu xac minh. Khong co thi tu choi (chua ho tro), khong tu lay MS hoan thien thay vao. Hien
 * service chua co duong xac minh nen `selectFor()` tra "chua ho tro" cho moi shot thi cong.
 */
final class ReferencePackSelector
{
    public const VERSION = 'reference-pack-selector-v2';

    public const POLICY_FINISHED = 'finished';

    public const POLICY_STATE_CHANGE = 'shape_preserving_state_change';

    /**
     * @param  array<string, mixed>  $pack  manifest ref_pack
     * @param  list<array<string, mixed>>  $accepted  quyet dinh chap nhan cua luot duyet pack {item_id, artifact_id, sha256}
     * @param  array{kind: string, finished: bool, views?: list<string>, details?: list<string>, room?: ?string, max_images: int, include_colors?: bool, state_source?: ?array{artifact_id: string, sha256: string}}  $request
     * @return array{ok: bool, error: ?string, policy: ?string, edit_input: ?array<string, mixed>, references: list<array<string, mixed>>}
     */
    public static function select(array $pack, array $accepted, array $request): array
    {
        $max = (int) $request['max_images'];
        $decisions = array_column($accepted, null, 'item_id');
        $usable = static fn (?array $entry): bool => $entry !== null && is_string($entry['artifact_id'] ?? null)
            && ($entry['status']['rule'] ?? null) === 'pass'
            && ($decisions[$entry['id']]['artifact_id'] ?? null) === $entry['artifact_id']
            && ($decisions[$entry['id']]['sha256'] ?? null) === $entry['sha256'];
        $ref = static fn (array $entry, string $role): array => ['role' => $role, 'item_id' => $entry['id'], 'artifact_id' => $entry['artifact_id'], 'sha256' => $entry['sha256']];
        $views = array_column((array) $pack['views'], null, 'id');
        $details = array_column((array) $pack['details'], null, 'id');
        $finished = (bool) $request['finished'];
        $required = [];
        $optional = [];

        if ($request['kind'] === 'interior') {
            $room = (array) (((array) $pack['rooms'])[$request['room'] ?? ''] ?? []);

            if (! $finished) {
                return self::fail('Shot nội thất đang thi công chưa hỗ trợ: pack chỉ có ảnh phòng hoàn thiện.');
            }

            foreach (['finished' => 'edit', 'materials' => 'material'] as $kind => $role) {
                if (! $usable($room[$kind] ?? null)) {
                    return self::fail("Phòng {$request['room']} chưa có {$kind} được duyệt trong pack.");
                }

                $required[] = $ref($room[$kind], $role);
            }
        } else {
            $wanted = $request['views'] ?? [];

            if ($wanted === []) {
                return self::fail('Shot ngoại thất phải nêu góc MS gần nhất.');
            }

            if (! $finished) {
                $state = $request['state_source'] ?? null;

                if (! is_array($state) || ! is_string($state['artifact_id'] ?? null) || $state['artifact_id'] === ''
                    || preg_match('/^[a-f0-9]{64}$/', (string) ($state['sha256'] ?? '')) !== 1) {
                    return self::fail('Shot thi công chưa hỗ trợ: cần ảnh nguồn đúng trạng thái thi công làm ảnh edit — MS hoàn thiện chỉ dùng làm hình dạng, không làm ảnh edit.');
                }

                $required[] = ['role' => 'edit', 'item_id' => 'state_source', 'artifact_id' => $state['artifact_id'], 'sha256' => $state['sha256']];
            }

            foreach ($wanted as $i => $view) {
                $entry = $views["view.{$view}"] ?? null;

                if (! $usable($entry)) {
                    return self::fail("Góc {$view} chưa được duyệt trong pack.");
                }

                $required[] = $ref($entry, $finished ? ($i === 0 ? 'edit' : 'shape') : 'shape_only');
            }

            foreach ($request['details'] ?? [] as $id) {
                if (! $finished) {
                    return self::fail("Chi tiết {$id} là ảnh hoàn thiện — không dùng cho shot đang thi công.");
                }

                if (! $usable($details[$id] ?? null)) {
                    return self::fail("Chi tiết {$id} chưa được duyệt trong pack.");
                }

                $required[] = $ref($details[$id], 'detail');
            }

            if (($request['include_colors'] ?? true) && $finished && $usable($pack['colors'] ?? null)) {
                $optional[] = $ref($pack['colors'], 'material');
            }
        }

        if (count($required) > $max) {
            return self::fail(sprintf('Cần %d reference bắt buộc nhưng tool profile chỉ cho %d (tính cả ảnh edit) — đổi cách gom nguồn, không tự bỏ.', count($required), $max));
        }

        $selected = [...$required, ...array_slice($optional, 0, $max - count($required))];
        $edit = array_values(array_filter($selected, static fn (array $r): bool => $r['role'] === 'edit'))[0] ?? null;

        return ['ok' => true, 'error' => null, 'policy' => $finished ? self::POLICY_FINISHED : self::POLICY_STATE_CHANGE, 'edit_input' => $edit,
            'references' => array_values(array_filter($selected, static fn (array $r): bool => $r['role'] !== 'edit'))];
    }

    /** @return array{ok: bool, error: ?string, policy: null, edit_input: null, references: list<never>} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'error' => $reason, 'policy' => null, 'edit_input' => null, 'references' => []];
    }
}
