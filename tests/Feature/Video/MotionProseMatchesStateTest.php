<?php

namespace Tests\Feature\Video;

use Tests\TestCase;

/**
 * PROSE CUA PHA KHONG DUOC TA MOT GIAI DOAN KHAC VOI ANH NGUON CUA NO.
 *
 * `requires_state` chi quyet dinh ANH NAO duoc gui cho Veo. No khong noi gi ve
 * cau chu, va do la mot khoang trong THAT — do duoc ngay 2026-08-07:
 *
 *   construction_hull  requires_state = hull_shell
 *                      prose: "multi-deck SUPERSTRUCTURE section hangs suspended
 *                              ... two workers on the hull DECK below"
 *   craftsmanship      requires_state = hull_shell
 *                      prose: "the towering WHITE-PAINTED hull ... scissor lift"
 *
 * Trong khi anh `hull_shell` la vo thep tho ho noc: khong boong, khong thuong
 * tang, khong son. Resolver bao SAN SANG vi hai chuoi enum bang nhau, va $0.36
 * suyt duoc tieu de mua hai clip ke sai vat.
 *
 * Va no se hong THEO MOT KIEU RAT KHO DOC: bang chung Veo da do ($0.36 o ca
 * engine v1/v2) noi prior cua ANH THANG cau chu — nen clip se khong ra thu prompt
 * ta, cung khong bao loi. Chi la mot doan video sai.
 *
 * TU VUNG CAM KHONG VIET O DAY. No lay tu `forbidden` cua chinh mat chuoi sinh ra
 * tam anh (`construction_chains/*.json`) — du lieu DA CO SAN va chua ai doc. Mat
 * `shell` cam deck/superstructure/paint vi anh cua no khong co nhung thu do; prose
 * cua mot canh khoi tu tam anh ay phai chiu dung rang buoc ay.
 */
class MotionProseMatchesStateTest extends TestCase
{
    /** @return array<string, list<string>> proves_state => tu bi cam */
    private function forbiddenByState(): array
    {
        $runnerDir = rtrim((string) config('video.runner.runner_dir', ''), '\\/');
        if ($runnerDir === '') {
            return [];
        }

        $glob = '/media_runtime/design/data/construction_chains/*.json';
        $files = array_merge(glob($runnerDir.$glob) ?: [], glob(dirname($runnerDir).$glob) ?: []);

        $map = [];
        foreach ($files as $file) {
            $chain = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($chain['chain'] ?? [] as $link) {
                $state = $link['proves_state'] ?? null;
                if ($state !== null && ($link['forbidden'] ?? []) !== []) {
                    $map[$state] = array_values(array_unique(
                        array_merge($map[$state] ?? [], $link['forbidden'])
                    ));
                }
            }
        }

        return $map;
    }

    public function test_phase_prose_never_describes_a_stage_its_source_image_cannot_show(): void
    {
        $forbidden = $this->forbiddenByState();
        if ($forbidden === []) {
            $this->markTestIncomplete('Khong doc duoc construction_chains/*.json (VIDEO_RUNNER_DIR?).');
        }

        $violations = [];
        $checked = 0;

        foreach (config('video.creation_arc.phase_sets', []) as $setKey => $set) {
            foreach ($set['phases'] ?? [] as $phaseKey => $phase) {
                $state = $phase['requires_state'] ?? null;
                if ($state === null || ! isset($forbidden[$state])) {
                    continue;   // trang thai chua co mat chuoi nao khai `forbidden`
                }
                $checked++;

                // Gop MOI cau chu se di vao prompt chuyen dong.
                $prose = strtolower(implode(' ', array_merge(
                    [$phase['composition_note'] ?? '', $phase['objective'] ?? ''],
                    $phase['micro_physics'] ?? [],
                )));

                foreach ($forbidden[$state] as $word) {
                    if (str_contains($prose, strtolower($word))) {
                        $violations[] = "{$setKey}.{$phaseKey} (requires_state={$state}) nhac '{$word}'";
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'Khong pha nao duoc kiem — test dang khong bao ve gi.');
        $this->assertSame([], $violations, sprintf(
            "%d mau thuan giua prose va anh nguon:\n  - %s\n\n".
            "Anh nguon cua nhung canh nay khong co nhung thu do. Bang chung Veo (\$0.36, engine v1/v2)\n".
            'noi prior cua ANH thang cau chu — nen clip se ra sai ma khong bao loi.',
            count($violations), implode("\n  - ", $violations),
        ));
    }
}
