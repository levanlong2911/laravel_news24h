<?php

namespace Tests\Unit;

use App\Models\VideoPlanningStage;
use App\Services\Video\ModelSheetService;
use App\Services\Video\YachtDesignService;
use App\Video\ModelSheet\ModelSheetMaster;
use App\Video\YachtDesign\DesignRunPolicy;
use ReflectionMethod;
use Tests\TestCase;

final class DesignRunPolicyTest extends TestCase
{
    private const INPUT = [
        'execution' => 'exec-v1', 'article_id' => 'a1', 'category_id' => 'c1', 'workflow' => 'w1', 'bundle_hash' => 'b1',
        'phase_id' => 'p1', 'phase_revision' => 8, 'prompt_sha256' => 'ps1', 'dna_stage_id' => 'd1',
        'check_version' => 'check-v2', 'model' => 'm1', 'effort' => 'high', 'max_tokens' => 1000, 'timeout_seconds' => 60, 'stream' => true, 'retry_times' => 0,
    ];

    private const PAYLOAD = [
        'project_id' => 'x', 'prompt_version' => 'v8', 'dna' => ['brief' => 'b'],
        'model_sheet' => ['version' => 'sheet-v1', 'cameras' => ['MS1' => ['azimuth_deg' => 0]], 'panels' => ['one'], 'rules' => ['keep'], 'max_chars' => 20000],
    ];

    public function test_a_model_sheet_config_change_is_reported_and_opens_nothing(): void
    {
        $changes = $this->changes(payload: ['model_sheet' => ['rules' => ['keep', 'a rule that changes what the sheet shows'], 'max_chars' => 27000] + self::PAYLOAD['model_sheet']]);

        self::assertSame(['rules', 'max_chars'], $changes['next_sheet'], 'the two runs are not treated as equal');
        self::assertSame([], $changes['design']);
        self::assertSame(['create' => false, 'regenerate' => false, 'status' => DesignRunPolicy::KEPT], $this->decide($changes));
    }

    public function test_a_camera_or_panel_change_asks_for_a_deliberate_new_design(): void
    {
        foreach (['cameras' => ['MS1' => ['azimuth_deg' => 45]], 'panels' => ['other'], 'version' => 'sheet-v2'] as $field => $value) {
            $changes = $this->changes(payload: ['model_sheet' => [$field => $value] + self::PAYLOAD['model_sheet']]);

            self::assertSame(['model_sheet.'.$field], $changes['design']);
            self::assertSame(['create' => false, 'regenerate' => true, 'status' => DesignRunPolicy::INPUT_CHANGED], $this->decide($changes));
        }
    }

    public function test_a_source_or_prompt_change_asks_for_a_deliberate_new_design(): void
    {
        foreach (['dna_stage_id' => 'd2', 'phase_revision' => 9, 'prompt_sha256' => 'ps2', 'bundle_hash' => 'b2', 'execution' => 'exec-v2'] as $field => $value) {
            $changes = $this->changes(input: [$field => $value] + self::INPUT);

            self::assertSame([$field], $changes['design']);
            self::assertSame(DesignRunPolicy::INPUT_CHANGED, $this->decide($changes)['status']);
        }
    }

    public function test_model_effort_or_check_changes_keep_the_design_and_only_concern_a_new_run(): void
    {
        $changes = $this->changes(input: ['model' => 'm2', 'effort' => 'max', 'check_version' => 'check-v3'] + self::INPUT);

        self::assertSame(['check_version', 'model', 'effort'], $changes['next_run']);
        self::assertSame([], $changes['design']);
        self::assertSame(DesignRunPolicy::KEPT, $this->decide($changes)['status']);
    }

    public function test_a_field_the_policy_does_not_know_asks_a_person_instead_of_being_ignored(): void
    {
        self::assertSame(['new_field'], $this->changes(input: ['new_field' => 1] + self::INPUT)['design']);
        self::assertSame(['model_sheet.new_field'], $this->changes(payload: ['model_sheet' => ['new_field' => 1] + self::PAYLOAD['model_sheet']])['design']);
        self::assertSame(['added'], $this->changes(payload: ['added' => 1] + self::PAYLOAD)['design']);
    }

    public function test_a_run_without_a_readable_snapshot_keeps_the_design_and_says_so(): void
    {
        $now = (string) json_encode(['model_sheet' => ['rules' => ['other']]] + self::PAYLOAD);
        $stored = ['payload_sha256' => hash('sha256', (string) json_encode(self::PAYLOAD))] + self::INPUT;

        foreach ([null, 'not the payload that was hashed'] as $snapshot) {
            $changes = DesignRunPolicy::changes($stored, self::INPUT, $snapshot, $now);

            self::assertTrue($changes['payload_unread']);
            self::assertSame(['create' => false, 'regenerate' => false, 'status' => DesignRunPolicy::KEPT], $this->decide($changes));
        }
    }

    public function test_the_same_payload_reports_no_change(): void
    {
        self::assertSame(DesignRunPolicy::NO_CHANGES, $this->changes());
    }

    public function test_a_value_that_changes_type_is_a_change(): void
    {
        foreach ([[123, '123'], [false, 0], [null, ''], [0, null], [[], (object) []], [[1, 2], [2, 1]]] as [$before, $after]) {
            self::assertSame(['dna'], $this->payloadChanges(['dna' => $before], ['dna' => $after])['design'], json_encode([$before, $after]));
        }

        self::assertSame(['max_chars'], $this->payloadChanges(['model_sheet' => ['max_chars' => 20000]], ['model_sheet' => ['max_chars' => '20000']])['next_sheet']);
        self::assertSame(['model_sheet.cameras'], $this->payloadChanges(['model_sheet' => ['cameras' => ['MS1' => ['lens' => 50]]]], ['model_sheet' => ['cameras' => ['MS1' => ['lens' => '50']]]])['design']);
    }

    public function test_a_missing_field_is_not_a_null_field(): void
    {
        self::assertSame(['added'], $this->payloadChanges(['dna' => 1], ['dna' => 1, 'added' => null])['design']);
        self::assertSame(['dna'], $this->payloadChanges(['dna' => ['a' => null]], ['dna' => (object) []])['design']);
        self::assertSame(['x'], DesignRunPolicy::changes(['x' => null] + self::INPUT, self::INPUT, null, '{}')['design'], 'the input fields as well');
    }

    public function test_only_the_order_of_object_keys_does_not_count(): void
    {
        $stored = '{"a":1,"b":{"x":1,"y":[{"p":1,"q":2}]}}';
        $current = '{"b":{"y":[{"q":2,"p":1}],"x":1},"a":1}';

        self::assertNotSame(hash('sha256', $stored), hash('sha256', $current));
        self::assertSame(DesignRunPolicy::NO_CHANGES, DesignRunPolicy::changes(['payload_sha256' => hash('sha256', $stored)] + self::INPUT, self::INPUT, $stored, $current));
    }

    public function test_every_rule_of_the_decision(): void
    {
        $none = DesignRunPolicy::NO_CHANGES;
        $changed = ['design' => ['phase_revision']] + $none;
        $row = static fn (bool $create, bool $regenerate, string $status): array => ['create' => $create, 'regenerate' => $regenerate, 'status' => $status];

        self::assertSame($row(false, false, DesignRunPolicy::LOCKED), DesignRunPolicy::decide(true, true, true, false, false, false, $changed), 'a lock wins over a failed design and a changed input');
        self::assertSame($row(false, false, DesignRunPolicy::NOT_READY), DesignRunPolicy::decide(false, false, false, false, false, false, $none));
        self::assertSame($row(true, false, DesignRunPolicy::NO_DESIGN), DesignRunPolicy::decide(true, false, false, false, false, false, $none));
        self::assertSame($row(false, true, DesignRunPolicy::REPLACEABLE), DesignRunPolicy::decide(true, true, false, false, false, true, $none), 'a failed design');
        self::assertSame($row(false, true, DesignRunPolicy::REPLACEABLE), DesignRunPolicy::decide(true, true, false, true, true, false, $none), 'warnings to confirm');
        self::assertSame($row(false, false, DesignRunPolicy::CURRENT), DesignRunPolicy::decide(true, true, false, true, true, true, $none));
        self::assertSame($row(false, true, DesignRunPolicy::INPUT_CHANGED), DesignRunPolicy::decide(true, true, false, false, true, true, $changed));
        self::assertSame($row(false, false, DesignRunPolicy::KEPT), DesignRunPolicy::decide(true, true, false, false, true, true, $none));
    }

    public function test_a_stored_design_is_trusted_by_its_own_records(): void
    {
        $raw = '{"design":1}';
        $good = ['raw_response' => $raw, 'output_hash' => hash('sha256', $raw), 'output_json' => ['raw_text' => $raw], 'input_json' => ['workflow' => 'w1', 'phase_id' => 'p1', '_meta' => ['manifest' => ['phase' => ['id' => 'p1']]]]];
        $stage = static fn (array $attributes): VideoPlanningStage => (new VideoPlanningStage)->forceFill($attributes);

        self::assertNull(DesignRunPolicy::integrityProblem($stage($good), 'w1'));
        self::assertNull(DesignRunPolicy::integrityProblem($stage(['output_hash' => null, 'output_json' => [], 'input_json' => []] + $good), 'w1'), 'a row from before these records were kept');
        self::assertStringContainsString('mã băm', (string) DesignRunPolicy::integrityProblem($stage(['raw_response' => '{"design":2}'] + $good), 'w1'));
        self::assertStringContainsString('phản hồi gốc', (string) DesignRunPolicy::integrityProblem($stage(['output_json' => ['raw_text' => 'other']] + $good), 'w1'));
        self::assertStringContainsString('workflow', (string) DesignRunPolicy::integrityProblem($stage($good), 'w2'));
        self::assertStringContainsString('Manifest', (string) DesignRunPolicy::integrityProblem($stage(['input_json' => ['phase_id' => 'p2'] + $good['input_json']] + $good), 'w1'));
    }

    /** @dataProvider sheetConfigs */
    public function test_every_field_of_the_model_sheet_input_has_a_decision(string $config): void
    {
        $sheet = (array) config($config);
        $builder = new ReflectionMethod(YachtDesignService::class, 'modelSheet');
        $fields = array_keys((array) $builder->invoke(null, $sheet));
        self::assertNotEmpty(ModelSheetService::views($sheet, new ModelSheetMaster((array) $sheet['master'])));

        self::assertSame([], array_values(array_diff($fields, DesignRunPolicy::NEXT_SHEET, DesignRunPolicy::PINNED_SHEET)), 'a new field needs its own decision here');
        self::assertSame([], array_values(array_diff(DesignRunPolicy::NEXT_SHEET, $fields, ['layout_guide'])), 'a field the policy names no longer exists');
        self::assertSame([], array_values(array_diff(DesignRunPolicy::PINNED_SHEET, $fields)), 'a field the policy names no longer exists');
    }

    /** @return array<string, array{0: string}> */
    public static function sheetConfigs(): array
    {
        return ['moto' => ['video.moto.model_sheet'], 'yacht' => ['video.superyacht.model_sheet']];
    }

    /**
     * @param  array<string, mixed>  $input  the current input
     * @param  array<string, mixed>  $payload  the current payload
     * @return array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}
     */
    private function changes(array $input = self::INPUT, array $payload = self::PAYLOAD): array
    {
        $stored = (string) json_encode(self::PAYLOAD);

        return DesignRunPolicy::changes(['payload_sha256' => hash('sha256', $stored), '_meta' => ['manifest' => []]] + self::INPUT, $input + ['payload_sha256' => 'ignored'], $stored, (string) json_encode($payload + self::PAYLOAD));
    }

    /**
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $now
     * @return array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}
     */
    private function payloadChanges(array $was, array $now): array
    {
        $stored = (string) json_encode($was);

        return DesignRunPolicy::changes(['payload_sha256' => hash('sha256', $stored)] + self::INPUT, self::INPUT, $stored, (string) json_encode($now));
    }

    /**
     * @param  array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}  $changes
     * @return array{create: bool, regenerate: bool, status: string} the decision for a passing, unlocked design
     */
    private function decide(array $changes): array
    {
        return DesignRunPolicy::decide(true, true, false, false, true, true, $changes);
    }
}
