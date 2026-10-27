<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Video\ReferencePack\ReferenceColorSheet;
use App\Video\ReferencePack\ReferencePackCompiler as Compiler;
use App\Video\ReferencePack\ReferencePackSelector;
use App\Video\ReferencePack\ReferencePackValidator;
use PHPUnit\Framework\TestCase;

/** Phase 3R thuan: khong boot Laravel, khong DB, khong goi model. */
final class ReferencePackTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../resources/ai/superyacht/contracts/ReferencePack.json';

    public function test_plan_reuses_the_eight_views_and_marks_absent_parts_not_applicable(): void
    {
        $items = $this->items($this->design());

        self::assertSame(array_map(static fn (string $v): string => "view.{$v}", Compiler::VIEW_IDS), array_column(array_slice($items, 0, 8), 'id'));
        self::assertSame([Compiler::METHOD_REUSE], array_values(array_unique(array_column(array_slice($items, 0, 8), 'method'))));
        self::assertSame(Compiler::METHOD_NOT_APPLICABLE, $this->find($items, 'detail.helideck')['method']);
        self::assertSame(Compiler::METHOD_NOT_APPLICABLE, $this->find($items, 'detail.swim_platform')['method']);
        self::assertSame(Compiler::METHOD_CODE, $this->find($items, 'color.exterior')['method']);
        self::assertSame(Compiler::METHOD_UNSUPPORTED, $this->find($items, 'video.turntable')['method']);
        self::assertSame(Compiler::METHOD_PENDING, $this->find($items, 'style.refs')['method']);
    }

    public function test_special_details_become_required_only_when_the_finished_state_has_the_part(): void
    {
        $design = $this->design();
        $design['canonical']['build_states'][1]['present_parts'][] = 'stern_platform';
        $design['yacht_bible']['exterior_description']['water_access'] = 'A fold-down stern platform at the waterline.';

        $platform = $this->find($this->items($design), 'detail.swim_platform');
        self::assertSame(Compiler::REQUIRED, $platform['requirement']);
        self::assertSame(Compiler::METHOD_RENDER, $platform['method']);
        self::assertSame('A fold-down stern platform at the waterline.', $platform['must_show']);
    }

    public function test_missing_design_facts_are_reported_not_invented(): void
    {
        $design = $this->design();
        unset($design['yacht_bible']['exterior_description']['bow'], $design['anchors']['interior']['INT01']);

        $items = $this->items($design);
        self::assertSame(Compiler::METHOD_MISSING, $this->find($items, 'detail.bow')['method']);
        self::assertStringContainsString('yacht_bible.exterior_description.bow', $this->find($items, 'detail.bow')['reason']);
        self::assertSame(Compiler::METHOD_MISSING, $this->find($items, 'room.INT01.finished')['method']);
        self::assertStringContainsString('anchors.interior.INT01', $this->find($items, 'room.INT01.materials')['reason']);
    }

    public function test_the_older_exterior_structure_is_read_with_its_own_source_path(): void
    {
        $design = $this->design();
        $design['yacht_bible']['exterior'] = $design['yacht_bible']['exterior_description'];
        unset($design['yacht_bible']['exterior_description']);
        $design['yacht_bible']['innovations'][0]['visible'] = $design['yacht_bible']['innovations'][0]['visible_expression'];
        unset($design['yacht_bible']['innovations'][0]['visible_expression']);

        $items = $this->items($design);
        self::assertSame(Compiler::METHOD_RENDER, $this->find($items, 'detail.bow')['method']);
        self::assertContains('yacht_bible.exterior.bow', $this->find($items, 'detail.bow')['sources']);
        self::assertStringContainsString('Twin light scoops', $this->find($items, 'detail.innovation.N1')['must_show']);
    }

    public function test_render_inputs_never_exceed_four_images_and_ms8_is_used_for_its_innovation(): void
    {
        $items = $this->items($this->design(), ['MS8' => 'close-up of N1 light scoops']);

        foreach (array_filter($items, static fn (array $i): bool => $i['method'] === Compiler::METHOD_RENDER) as $item) {
            self::assertLessThanOrEqual(4, count($item['inputs']), $item['id']);
        }

        self::assertSame('MS8', $this->find($items, 'detail.innovation.N1')['inputs'][0]['view']);
        self::assertSame('MS7', $this->find($items, 'detail.innovation.N2')['inputs'][0]['view']);
    }

    public function test_prompts_use_declared_facts_and_forbid_text(): void
    {
        $design = $this->design();
        $facts = Compiler::facts($design);
        $items = Compiler::plan($facts, []);
        $bow = Compiler::prompt($this->find($items, 'detail.bow'), $facts);
        $room = Compiler::prompt($this->find($items, 'room.INT01.finished'), $facts);
        $board = Compiler::prompt($this->find($items, 'room.INT01.materials'), $facts);

        self::assertStringContainsString('A rounded upright stem.', $bow);
        self::assertStringContainsString('No text', $bow);
        self::assertStringContainsString('honed limestone', $room);
        self::assertStringContainsString('Filtered overhead light well', $room);
        self::assertStringNotContainsString('Basalt', $room, 'Nội thất không suy từ ngoại thất.');
        self::assertStringContainsString('honed limestone; rift-cut ash', $board);
    }

    public function test_color_sheet_keeps_every_role_and_measures_back_to_the_declared_hex(): void
    {
        $colors = Compiler::facts($this->design())['colors'];
        $png = ReferenceColorSheet::png($colors);
        $measured = ReferenceColorSheet::measure($png, $colors);

        self::assertSame(['hull', 'superstructure', 'accent'], array_column($measured, 'role'), 'Cùng HEX vẫn giữ đủ vai trò.');
        self::assertSame([0, 0, 0], array_column($measured, 'max_channel_delta'));
        self::assertSame(['#30302E', '#30302E', '#898379'], array_column($measured, 'measured'));
    }

    public function test_missing_style_and_turntable_do_not_block_exterior_readiness(): void
    {
        $items = $this->items($this->design());
        $manifest = ReferencePackValidator::manifest($this->sources(), $items, $this->results($items));

        self::assertSame('ready', $manifest['readiness']['exterior']['status']);
        self::assertSame('pending_5l', $manifest['readiness']['style']['status']);
        self::assertSame('unsupported', $manifest['readiness']['video']['status']);
        self::assertSame('pass', $manifest['validation']['rule']);
        self::assertSame('unchecked', $manifest['validation']['vision'], 'Không báo Vision đạt khi chưa có cổng Vision.');
        self::assertSame([], ReferencePackValidator::schemaErrors($manifest, self::SCHEMA));
    }

    public function test_a_missing_required_detail_or_a_rejected_one_blocks_exterior(): void
    {
        $items = $this->items($this->design());
        $results = $this->results($items);
        unset($results['detail.bow']);
        $results['detail.stern']['acceptance'] = 'rejected';

        $manifest = ReferencePackValidator::manifest($this->sources(), $items, $results);
        self::assertSame('incomplete', $manifest['readiness']['exterior']['status']);
        self::assertContains('detail.bow', $manifest['readiness']['exterior']['blocking']);
        self::assertContains('detail.stern', $manifest['readiness']['exterior']['blocking']);
        self::assertSame('fail', $manifest['validation']['rule']);
    }

    public function test_a_file_that_no_longer_matches_its_hash_fails_rule(): void
    {
        $items = $this->items($this->design());
        $results = $this->results($items);
        $results['view.MS3']['file_ok'] = false;

        $status = ReferencePackValidator::itemStatus($this->find($items, 'view.MS3'), $results['view.MS3']);
        self::assertSame('fail', $status['rule']);
        self::assertStringContainsString('sha256', $status['problems'][0]);
    }

    public function test_interior_readiness_is_per_room(): void
    {
        $items = $this->items($this->design());
        $results = $this->results($items, rooms: true);
        unset($results['room.INT02.materials']);

        $readiness = ReferencePackValidator::manifest($this->sources(), $items, $results)['readiness']['interior'];
        self::assertSame('partial', $readiness['status']);
        self::assertSame(['INT01'], $readiness['ready_rooms']);
    }

    public function test_selector_counts_the_edit_image_and_never_drops_a_required_reference(): void
    {
        $items = $this->items($this->design());
        $pack = ReferencePackValidator::manifest($this->sources(), $items, $this->results($items));

        $accepted = ReferencePackValidator::acceptedEntries($pack);

        $ok = ReferencePackSelector::select($pack, $accepted, ['kind' => 'exterior', 'finished' => true, 'views' => ['MS2', 'MS1'], 'details' => ['detail.bow'], 'max_images' => 4]);
        self::assertTrue($ok['ok']);
        self::assertSame('view.MS2', $ok['edit_input']['item_id']);
        self::assertSame(['shape', 'detail', 'material'], array_column($ok['references'], 'role'));

        $tooMany = ReferencePackSelector::select($pack, $accepted, ['kind' => 'exterior', 'finished' => true, 'views' => ['MS2', 'MS1', 'MS3'], 'details' => ['detail.bow', 'detail.stern'], 'max_images' => 4]);
        self::assertFalse($tooMany['ok']);
        self::assertStringContainsString('không tự bỏ', $tooMany['error']);
    }

    public function test_selector_uses_only_items_with_a_recorded_acceptance_for_the_same_file(): void
    {
        $items = $this->items($this->design());
        $pack = ReferencePackValidator::manifest($this->sources(), $items, $this->results($items));
        $request = ['kind' => 'exterior', 'finished' => true, 'views' => ['MS2'], 'details' => ['detail.bow'], 'max_images' => 4];

        self::assertFalse(ReferencePackSelector::select($pack, [], $request)['ok'], 'Sẵn sàng để duyệt không phải được phép dùng.');

        $accepted = ReferencePackValidator::acceptedEntries($pack);
        $bow = array_search('detail.bow', array_column($accepted, 'item_id'), true);
        $accepted[$bow]['sha256'] = str_repeat('0', 64);
        $refused = ReferencePackSelector::select($pack, $accepted, $request);
        self::assertFalse($refused['ok'], 'Duyệt cho file khác thì không dùng được file hiện tại.');
        self::assertStringContainsString('detail.bow', $refused['error']);
    }

    public function test_approval_decisions_cover_room_boards(): void
    {
        $items = $this->items($this->design());
        $pack = ReferencePackValidator::manifest($this->sources(), $items, $this->results($items, rooms: true));
        $accepted = array_column(ReferencePackValidator::acceptedEntries($pack), 'basis', 'item_id');

        self::assertSame('inherited_model_sheet_review', $accepted['view.MS1']);
        self::assertSame('manual_pack_review', $accepted['room.INT01.finished']);
        self::assertSame('manual_pack_review', $accepted['room.INT02.materials']);
        self::assertTrue(ReferencePackSelector::select($pack, ReferencePackValidator::acceptedEntries($pack), ['kind' => 'interior', 'finished' => true, 'room' => 'INT01', 'max_images' => 4])['ok']);
    }

    public function test_selector_keeps_finished_references_out_of_construction_shots(): void
    {
        $items = $this->items($this->design());
        $pack = ReferencePackValidator::manifest($this->sources(), $items, $this->results($items, rooms: true));
        $accepted = ReferencePackValidator::acceptedEntries($pack);

        $unsupported = ReferencePackSelector::select($pack, $accepted, ['kind' => 'exterior', 'finished' => false, 'views' => ['MS1', 'MS2'], 'max_images' => 4]);
        self::assertFalse($unsupported['ok'], 'Không lấy MS hoàn thiện làm ảnh edit cho shot thi công.');
        self::assertStringContainsString('chưa hỗ trợ', $unsupported['error']);

        $state = ['artifact_id' => 'bs07', 'sha256' => str_repeat('e', 64)];
        $shape = ReferencePackSelector::select($pack, $accepted, ['kind' => 'exterior', 'finished' => false, 'views' => ['MS1', 'MS2'], 'max_images' => 4, 'state_source' => $state]);
        self::assertTrue($shape['ok']);
        self::assertSame(ReferencePackSelector::POLICY_STATE_CHANGE, $shape['policy']);
        self::assertSame('state_source', $shape['edit_input']['item_id']);
        self::assertSame(['shape_only', 'shape_only'], array_column($shape['references'], 'role'), 'MS chỉ giữ hình dạng; không đính kèm bảng màu hoàn thiện.');

        self::assertFalse(ReferencePackSelector::select($pack, $accepted, ['kind' => 'exterior', 'finished' => false, 'views' => ['MS1'], 'details' => ['detail.bow'], 'max_images' => 4, 'state_source' => $state])['ok']);
        self::assertFalse(ReferencePackSelector::select($pack, $accepted, ['kind' => 'interior', 'finished' => false, 'room' => 'INT01', 'max_images' => 4])['ok']);
    }

    public function test_content_fingerprint_ignores_check_evidence_but_follows_files_and_acceptance(): void
    {
        $items = $this->items($this->design());
        $results = $this->results($items);
        $shallow = ReferencePackValidator::manifest($this->sources(), $items, $results);
        $results['color.exterior']['evidence'] = ['measured' => [['role' => 'hull', 'max_channel_delta' => 0]]];
        $deep = ReferencePackValidator::manifest($this->sources(), $items, $results);

        self::assertNotSame($shallow['manifest_sha256'], $deep['manifest_sha256']);
        self::assertSame($shallow['content_sha256'], $deep['content_sha256'], 'Lần kiểm sâu và lần mở trang so được với nhau.');

        $results['detail.bow']['sha256'] = hash('sha256', 'replaced');
        self::assertNotSame($shallow['content_sha256'], ReferencePackValidator::manifest($this->sources(), $items, $results)['content_sha256']);
        $results = $this->results($items);
        $results['view.MS1']['acceptance'] = 'pending';
        self::assertNotSame($shallow['content_sha256'], ReferencePackValidator::manifest($this->sources(), $items, $results)['content_sha256']);
    }

    public function test_a_source_problem_reported_by_the_caller_fails_rule(): void
    {
        $items = $this->items($this->design());
        $results = $this->results($items);
        $results['view.MS2'] = ['acceptance' => 'pending', 'problems' => ['Artifact của MS2 có sha256 khác snapshot.']] + $results['view.MS2'];

        $manifest = ReferencePackValidator::manifest($this->sources(), $items, $results);
        self::assertSame('fail', $manifest['views'][1]['status']['rule']);
        self::assertSame('pending', $manifest['views'][1]['status']['acceptance']);
        self::assertContains('view.MS2', $manifest['readiness']['exterior']['blocking']);
        self::assertNotContains('view.MS2', array_column(ReferencePackValidator::acceptedEntries($manifest), 'item_id'));
    }

    /** @return array<string, mixed> */
    private function design(): array
    {
        return [
            'yacht_bible' => [
                'name' => 'Testa',
                'exterior_description' => ['bow' => 'A rounded upright stem.', 'stern' => 'A vertical transom.', 'glazing' => 'Isolated deep-reveal windows.', 'helideck' => 'No helideck.'],
                'colors' => [
                    ['role' => 'hull', 'name' => 'Basalt Charcoal', 'hex' => '#30302e'],
                    ['role' => 'superstructure', 'name' => 'Basalt Charcoal', 'hex' => '#30302E'],
                    ['role' => 'accent', 'name' => 'Clay Grey', 'hex' => '#898379'],
                ],
                'innovations' => [
                    ['id' => 'N1', 'name' => 'Light scoops', 'position' => 'Forward crest.', 'geometry' => 'Twin light scoops 2 m wide.', 'visible_expression' => 'Two recessed mirrors.'],
                    ['id' => 'N2', 'name' => 'Cleft', 'position' => 'Midships.', 'geometry' => 'Open slot.', 'visible_expression' => 'A long cut.'],
                ],
                'rooms' => [
                    ['id' => 'INT01', 'name' => 'Owner chamber', 'deck' => 'Main', 'position' => 'Central.', 'size_feel' => '11 m long.', 'materials' => ['honed limestone', 'rift-cut ash'], 'palette' => ['#B7AA96'], 'signature_detail' => 'A curved reflector.', 'window_type' => 'Filtered overhead light well'],
                    ['id' => 'INT02', 'name' => 'Salon', 'deck' => 'Main', 'position' => 'Forward.', 'size_feel' => '9 m wide.', 'materials' => ['woven wool'], 'palette' => [], 'signature_detail' => null, 'window_type' => 'Two side windows'],
                ],
            ],
            'anchors' => [
                'yacht' => 'A 140-metre yacht.',
                'finished_surface' => 'Matte Basalt Charcoal hull.',
                'interior' => ['INT01' => 'The owner chamber combines honed limestone and ash.', 'INT02' => 'The salon is lined in wool.'],
            ],
            'canonical' => [
                'build_states' => [
                    ['id' => 'BS11', 'kind' => 'construction', 'present_parts' => ['hull_shell']],
                    ['id' => 'BS12', 'kind' => 'finished', 'present_parts' => ['hull_shell', 'main_deck', 'superstructure', 'glazing']],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(array $design, array $labels = []): array
    {
        return Compiler::plan(Compiler::facts($design), $labels);
    }

    /** @return array<string, mixed> */
    private function find(array $items, string $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail("Không có mục {$id}");
    }

    /** @return array<string, mixed> */
    private function sources(): array
    {
        return ['project_id' => 'p1', 'design_stage_id' => 'd1', 'design_canonical_hash' => 'sha256:'.str_repeat('a', 64), 'design_raw_sha256' => str_repeat('b', 64),
            'model_sheet' => ['set_id' => 's1', 'review_stage_id' => 'r1', 'master_image_id' => 'm1', 'master_artifact_id' => 'a1', 'master_sha256' => str_repeat('c', 64), 'crops' => array_fill(0, 8, ['view_id' => 'MS1'])],
            'phase' => ['code' => 'phase-3r', 'id' => 'ph', 'revision' => 2, 'sha256' => str_repeat('d', 64)]];
    }

    /** @return array<string, array<string, mixed>> mot ket qua dat cho moi muc co the tao */
    private function results(array $items, bool $rooms = false): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! in_array($item['method'], [Compiler::METHOD_REUSE, Compiler::METHOD_CODE, Compiler::METHOD_RENDER], true) || ($item['group'] === 'rooms' && ! $rooms)) {
                continue;
            }

            [$w, $h] = $item['group'] === 'views' ? [960, 1080] : [1536, 1024];
            $out[$item['id']] = ['artifact_id' => 'art-'.$item['id'], 'sha256' => hash('sha256', $item['id']), 'mime' => 'image/png', 'width' => $w, 'height' => $h,
                'file_ok' => true, 'origin' => $item['method'], 'acceptance' => $item['group'] === 'views' ? 'inherited' : 'pending'];
        }

        return $out;
    }
}
