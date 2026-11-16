<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * A hand revision of a stored moto design answer, limited to what a reviewer may correct without
 * designing anything: the refs of a build state, a text the model-sheet prompt takes that holds an
 * id, and the surface of a finished state that must be null.
 *
 * It works on the answer as JSON decodes it to objects, so an empty object stays an object when the
 * revised answer is encoded again. It never adds, removes or renames a record of the design: a ref is
 * only replaced by an id the answer already defines, of a kind the refs take. Each change states the
 * value it expects to find, so a patch written against another answer is refused, never merged.
 */
final class MotoDesignPatch
{
    public const VERSION = 'moto-design-patch-v1';

    /** @var array<string, list<string>> every operation by the keys it takes besides op and reason */
    private const OPERATIONS = [
        'remove_ref' => ['state', 'ref'],
        'replace_ref' => ['state', 'from', 'to'],
        'set_text' => ['path', 'before', 'after'],
        'set_surface_null' => ['state'],
    ];

    /** @var list<string> the states whose surface the schema wants null */
    private const FINISHED_STATES = ['BS11', 'BS12'];

    /**
     * @param  array<string, string>  $sources  MotoSheetPrompt::composed() sources of the answer: every text the model-sheet prompt takes, by its path with the id of its record
     * @return list<string> the paths, relative to data, of the texts the prompt takes that still hold an id there: the only texts set_text may change
     */
    public static function textPaths(array $sources): array
    {
        $paths = [];

        foreach ($sources as $source => $text) {
            if (is_string($text) && MotoDesignCheck::promptIds($text) !== []) {
                $paths[] = explode(' — ', (string) $source, 2)[0];
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, mixed>  $data  the data of the stored answer
     * @param  list<string>  $textPaths  the result of textPaths() for that answer
     * @return array{proposed: list<array<string, mixed>>, decisions: list<array<string, mixed>>} the changes the problems of the answer lead to without a choice — a duplicate or a ref of a kind the refs never take is removed, a surface of a finished state set to null — and the ones a person must decide, with what is missing
     */
    public static function proposals(array $data, array $textPaths): array
    {
        $proposed = [];
        $decisions = [];

        foreach (MotoDesignRefs::storyRefProblems($data) as $problem) {
            $reason = $problem['message'];

            if (in_array($problem['code'], ['duplicate', 'wrong_kind'], true)) {
                $proposed[] = ['op' => 'remove_ref', 'state' => $problem['state'], 'ref' => $problem['ref'], 'reason' => $reason];
            } else {
                $decisions[] = ['op' => 'replace_ref', 'state' => $problem['state'], 'from' => $problem['ref'], 'to' => null, 'reason' => $reason,
                    'decide' => 'Chọn một id có thật cho "to", hoặc đổi op thành remove_ref (xoá "from"/"to", ghi "ref") nếu tham chiếu này không cần.'
                        .($problem['candidate'] === null ? '' : ' Id gần giống: '.$problem['candidate'].'.')];
            }
        }

        foreach ($textPaths as $path) {
            $text = self::read($data, $path);

            if (is_string($text)) {
                $decisions[] = ['op' => 'set_text', 'path' => $path, 'before' => $text, 'after' => null,
                    'reason' => "{$path} chứa ID ".implode(', ', MotoDesignCheck::promptIds($text)).' mà prompt ảnh không được có.',
                    'decide' => 'Viết lại "after": cùng nghĩa, tả bằng lời (tên bộ phận, giai đoạn, màu) thay cho mã.'];
            }
        }

        foreach ((array) ($data['build_states'] ?? []) as $state) {
            if (is_array($state) && in_array($state['id'] ?? null, self::FINISHED_STATES, true) && ($state['surface'] ?? null) !== null) {
                $proposed[] = ['op' => 'set_surface_null', 'state' => $state['id'], 'reason' => "{$state['id']}.surface phải là null; surfaces vẫn giữ mức hoàn thiện từng nhóm."];
            }
        }

        return ['proposed' => $proposed, 'decisions' => $decisions];
    }

    /**
     * @param  object  $answer  the stored answer as JSON decodes it; it is not changed
     * @param  array<string, mixed>  $patch  reason and changes, each change an operation of OPERATIONS with an optional reason of its own
     * @param  list<string>  $textPaths  the result of textPaths() for that answer
     * @return array{answer: ?object, changes: list<array{path: string, before: string, after: string, reason: string}>, problem: ?string} the revised answer and what changed; problem says why nothing was revised
     */
    public static function apply(object $answer, array $patch, array $textPaths): array
    {
        $refuse = static fn (string $problem): array => ['answer' => null, 'changes' => [], 'problem' => $problem];
        $reason = $patch['reason'] ?? null;
        $changes = $patch['changes'] ?? null;

        if (array_diff(array_keys($patch), ['reason', 'changes']) !== []) {
            return $refuse('Bản sửa chỉ có reason và changes.');
        }

        if (! is_string($reason) || trim($reason) === '' || ! is_array($changes) || $changes === [] || ! array_is_list($changes)) {
            return $refuse('Bản sửa cần reason và một danh sách changes không rỗng.');
        }

        $revised = self::copy($answer);
        $data = is_object($revised->data ?? null) ? $revised->data : null;

        if ($data === null || ! is_array($data->build_states ?? null)) {
            return $refuse('Câu trả lời đã lưu không có data.build_states để sửa.');
        }

        $registry = MotoDesignRefs::registry((array) json_decode((string) json_encode($data), true));
        $done = [];

        foreach ($changes as $at => $change) {
            $op = is_array($change) ? ($change['op'] ?? null) : null;
            $keys = is_string($op) ? (self::OPERATIONS[$op] ?? null) : null;

            if ($keys === null || array_diff(array_keys($change), ['op', 'reason', ...$keys]) !== [] || array_diff($keys, array_keys($change)) !== []) {
                return $refuse("changes[{$at}]: op phải là ".implode(', ', array_keys(self::OPERATIONS)).' với đúng các khóa của nó.');
            }

            $why = trim(is_string($change['reason'] ?? null) && trim($change['reason']) !== '' ? $change['reason'] : $reason);
            $result = match ($op) {
                'remove_ref', 'replace_ref' => self::ref($data, $change, $registry, $why),
                'set_text' => self::text($data, $change, $textPaths, $why),
                default => self::surface($data, $change, $why),
            };

            if (is_string($result)) {
                return $refuse("changes[{$at}]: {$result}");
            }

            $done[] = $result;
        }

        return ['answer' => $revised, 'changes' => $done, 'problem' => null];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, array<string, true>>  $registry  the ids of the answer before the patch
     * @return array{path: string, before: string, after: string, reason: string}|string the change made, or why it cannot be
     */
    private static function ref(object $data, array $change, array $registry, string $why): array|string
    {
        $state = self::state($data, $change['state']);

        if ($state === null || ! is_array($state->refs ?? null)) {
            return 'không có build state '.MotoDesignRefs::display($change['state']).' có refs.';
        }

        $before = $state->refs;
        $from = $change['op'] === 'remove_ref' ? $change['ref'] : $change['from'];
        $found = array_keys(array_filter($before, static fn (mixed $ref): bool => $ref === $from));

        if ($found === []) {
            return "{$state->id}.refs không có ".MotoDesignRefs::display($from).' — bản sửa viết cho một câu trả lời khác.';
        }

        $refs = $before;

        if ($change['op'] === 'remove_ref') {
            // A ref written twice keeps its first place; any other is the one ref of its value.
            unset($refs[end($found)]);
        } else {
            $to = $change['to'];

            if (! is_string($to) || MotoDesignRefs::refProblem($to, $registry, MotoDesignRefs::STORY_REF_KINDS) !== null) {
                return 'id thay thế '.MotoDesignRefs::display($to).' phải là id '.implode(', ', MotoDesignRefs::STORY_REF_KINDS).' đã có trong hồ sơ — bản sửa không thêm hay đổi tên bản ghi nào.';
            }

            if (in_array($to, $before, true)) {
                return "{$state->id}.refs đã có ".MotoDesignRefs::display($to).' — dùng remove_ref để bỏ '.MotoDesignRefs::display($from).'.';
            }

            $refs[$found[0]] = $to;
        }

        $state->refs = array_values($refs);

        return ['path' => "{$state->id}.refs", 'before' => self::json($before), 'after' => self::json($state->refs), 'reason' => $why];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  list<string>  $textPaths
     * @return array{path: string, before: string, after: string, reason: string}|string
     */
    private static function text(object $data, array $change, array $textPaths, string $why): array|string
    {
        $path = $change['path'];

        if (! is_string($path) || ! in_array($path, $textPaths, true)) {
            return 'chỉ sửa được một text mà prompt model sheet lấy và đang chứa ID ('.(implode(', ', $textPaths) ?: 'không có text nào như vậy').'), không phải '.MotoDesignRefs::display($path).'.';
        }

        $segments = explode('.', $path);
        $field = array_pop($segments);
        $holder = self::node($data, $segments);
        $current = is_object($holder) ? ($holder->{$field} ?? null) : null;

        if (! is_string($current) || $current !== $change['before']) {
            return "{$path} không còn là before đã ghi — bản sửa viết cho một câu trả lời khác.";
        }

        $after = $change['after'];

        if (! is_string($after) || trim($after) === '' || $after === $current) {
            return "{$path}: after phải là một text khác before.";
        }

        if (MotoDesignCheck::promptIds($after) !== []) {
            return "{$path}: after vẫn chứa ID ".implode(', ', MotoDesignCheck::promptIds($after)).'.';
        }

        $holder->{$field} = $after;

        return ['path' => $path, 'before' => self::json($current), 'after' => self::json($after), 'reason' => $why];
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{path: string, before: string, after: string, reason: string}|string
     */
    private static function surface(object $data, array $change, string $why): array|string
    {
        $state = self::state($data, $change['state']);

        if ($state === null || ! in_array($state->id, self::FINISHED_STATES, true)) {
            return 'set_surface_null chỉ áp cho '.implode(' và ', self::FINISHED_STATES).'.';
        }

        if (($state->surface ?? null) === null) {
            return "{$state->id}.surface đã là null.";
        }

        $before = $state->surface;
        $state->surface = null;

        return ['path' => "{$state->id}.surface", 'before' => self::json($before), 'after' => 'null', 'reason' => $why];
    }

    /** @return ?object the build state with this id, found by id and never by its place */
    private static function state(object $data, mixed $id): ?object
    {
        foreach ((array) $data->build_states as $state) {
            if (is_object($state) && ($state->id ?? null) === $id && is_string($id)) {
                return $state;
            }
        }

        return null;
    }

    /** @param  list<string>  $segments */
    private static function node(object $data, array $segments): mixed
    {
        $node = $data;

        foreach ($segments as $segment) {
            $node = match (true) {
                is_object($node) => $node->{$segment} ?? null,
                is_array($node) && ctype_digit($segment) => $node[(int) $segment] ?? null,
                default => null,
            };
        }

        return $node;
    }

    /** @param  array<string, mixed>  $data */
    private static function read(array $data, string $path): mixed
    {
        $node = $data;

        foreach (explode('.', $path) as $segment) {
            $node = is_array($node) ? ($node[ctype_digit($segment) ? (int) $segment : $segment] ?? null) : null;
        }

        return $node;
    }

    private static function copy(object $answer): object
    {
        return unserialize(serialize($answer));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return string the revised answer encoded the way the revision stores it */
    public static function encode(object $answer): string
    {
        return (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
