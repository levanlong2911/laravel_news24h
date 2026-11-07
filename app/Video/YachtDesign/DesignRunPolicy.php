<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

use App\Models\VideoPlanningStage;
use App\Services\Video\PlanningStageStore;

/**
 * What a difference between a stored design run and the run the code would send now allows.
 *
 * The comparison stays complete: every differing field is reported and none counts as equal. A
 * different input only says the inputs differ. It never opens a paid run by itself and never takes
 * validity from a stored design; whether a new design may be created is decided here, apart from it.
 */
final class DesignRunPolicy
{
    public const LOCKED = 'locked';

    public const NOT_READY = 'not_ready';

    public const NO_DESIGN = 'no_design';

    public const REPLACEABLE = 'replaceable';

    public const CURRENT = 'current';

    public const INPUT_CHANGED = 'input_changed';

    public const KEPT = 'kept';

    public const NO_CHANGES = ['design' => [], 'next_sheet' => [], 'next_run' => [], 'payload_unread' => false];

    /** how a new run would call the model and check its answer; says nothing about a design already written */
    public const NEXT_RUN = ['model', 'effort', 'max_tokens', 'timeout_seconds', 'stream', 'retry_times', 'check_version'];

    /** model sheet fields every new model sheet run reads from the config itself, so a change reaches it without a new design */
    public const NEXT_SHEET = ['framing', 'detail_framing', 'rules', 'presentation', 'studio', 'layout_guide', 'max_chars'];

    /** model sheet fields a design is written and checked against */
    public const PINNED_SHEET = ['version', 'cameras', 'camera_convention', 'master', 'layout', 'panels'];

    /**
     * @param  array<string, mixed>  $storedInput  the input_json of the stored run
     * @param  array<string, mixed>  $currentInput  the input of the run the code would send now
     * @param  ?string  $storedPayload  the payload the stored run sent, null when no snapshot kept it
     * @return array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool} an unknown field counts as design, so it asks a person
     */
    public static function changes(array $storedInput, array $currentInput, ?string $storedPayload, string $currentPayload): array
    {
        $changes = self::NO_CHANGES;
        $ignored = [PlanningStageStore::METADATA_KEY => true, 'payload_sha256' => true];

        foreach (self::differing(array_diff_key($storedInput, $ignored), array_diff_key($currentInput, $ignored)) as $field) {
            $changes[in_array($field, self::NEXT_RUN, true) ? 'next_run' : 'design'][] = $field;
        }

        $storedHash = is_string($storedInput['payload_sha256'] ?? null) ? $storedInput['payload_sha256'] : null;

        if ($storedHash !== null && hash_equals($storedHash, hash('sha256', $currentPayload))) {
            return $changes;
        }

        $was = $storedHash !== null && $storedPayload !== null && hash_equals($storedHash, hash('sha256', $storedPayload)) ? json_decode($storedPayload) : null;
        $now = json_decode($currentPayload);

        if (! $was instanceof \stdClass || ! $now instanceof \stdClass) {
            $changes['payload_unread'] = true;

            return $changes;
        }

        $wasFields = get_object_vars($was);
        $nowFields = get_object_vars($now);

        foreach (self::differing($wasFields, $nowFields) as $field) {
            if ($field !== 'model_sheet' || ! ($wasFields[$field] ?? null) instanceof \stdClass || ! ($nowFields[$field] ?? null) instanceof \stdClass) {
                $changes['design'][] = $field;

                continue;
            }

            foreach (self::differing(get_object_vars($wasFields[$field]), get_object_vars($nowFields[$field])) as $sheetField) {
                if (in_array($sheetField, self::NEXT_SHEET, true)) {
                    $changes['next_sheet'][] = $sheetField;
                } else {
                    $changes['design'][] = 'model_sheet.'.$sheetField;
                }
            }
        }

        return $changes;
    }

    /**
     * @param  bool  $ready  a run can be prepared from the current sources
     * @param  bool  $hasDesign  the project has a succeeded design
     * @param  bool  $locked  that design was approved with its model sheet
     * @param  bool  $exact  its input is the current input
     * @param  bool  $usable  its stored answer holds together and passed
     * @param  bool  $clean  it carries no warning to confirm
     * @param  array{design: list<string>, next_sheet: list<string>, next_run: list<string>, payload_unread: bool}  $changes
     * @return array{create: bool, regenerate: bool, status: string} regenerate is always a deliberate paid action, never something a run starts by itself
     */
    public static function decide(bool $ready, bool $hasDesign, bool $locked, bool $exact, bool $usable, bool $clean, array $changes): array
    {
        return match (true) {
            $locked => ['create' => false, 'regenerate' => false, 'status' => self::LOCKED],
            ! $ready => ['create' => false, 'regenerate' => false, 'status' => self::NOT_READY],
            ! $hasDesign => ['create' => true, 'regenerate' => false, 'status' => self::NO_DESIGN],
            ! $usable || ! $clean => ['create' => false, 'regenerate' => true, 'status' => self::REPLACEABLE],
            $exact => ['create' => false, 'regenerate' => false, 'status' => self::CURRENT],
            $changes['design'] !== [] => ['create' => false, 'regenerate' => true, 'status' => self::INPUT_CHANGED],
            default => ['create' => false, 'regenerate' => false, 'status' => self::KEPT],
        };
    }

    /** @return ?string why a stored design cannot be trusted, by what the run itself recorded: its answer, its workflow and its manifest */
    public static function integrityProblem(VideoPlanningStage $stage, string $workflow): ?string
    {
        $raw = (string) $stage->raw_response;
        $output = is_array($stage->output_json) ? $stage->output_json : [];
        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $manifest = PlanningStageStore::metadataOf($input)['manifest'] ?? null;

        if ($stage->output_hash !== null && ! hash_equals((string) $stage->output_hash, hash('sha256', $raw))) {
            return 'Phản hồi đã lưu của bản thiết kế không khớp mã băm.';
        }

        if (isset($output['raw_text']) && $output['raw_text'] !== $raw) {
            return 'Nội dung đã lưu của bản thiết kế khác phản hồi gốc.';
        }

        if ($workflow !== '' && is_string($input['workflow'] ?? null) && $input['workflow'] !== $workflow) {
            return 'Bản thiết kế thuộc workflow '.$input['workflow'].', không phải workflow của dự án.';
        }

        if (is_array($manifest) && is_string($input['phase_id'] ?? null) && is_string($manifest['phase']['id'] ?? null) && $manifest['phase']['id'] !== $input['phase_id']) {
            return 'Manifest của bản thiết kế không khớp prompt đã ghi.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $was
     * @param  array<string, mixed>  $now
     * @return list<string> a missing field differs from null, 123 from "123", false from 0 and {} from []; only the order of an object's keys does not count
     */
    private static function differing(array $was, array $now): array
    {
        $fields = [];

        foreach (array_unique([...array_keys($was), ...array_keys($now)]) as $field) {
            if (! array_key_exists($field, $was) || ! array_key_exists($field, $now) || self::canonical($was[$field]) !== self::canonical($now[$field])) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);

            return ['object' => array_map(self::canonical(...), $fields)];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
