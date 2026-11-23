<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Superyacht\Support\CanonicalJson;

/**
 * The image plan of a moto-locations-v4 dossier, read by code (loc-shared-reference-v1): one plate of each place is its
 * standard plate — the first plate of the place in the dossier that may render, unless a person chose another — the
 * image chosen for that plate is the reference of the place, and every other plate of the place is rendered with that
 * image sent as a source file. The plan only names plates: which artifact is in use for a plate is what the image
 * selection of Phase 5 already holds. Nothing here reads a database or a file, writes a value the answer does not hold
 * or judges its words — it groups plates by place and compares ids and hashes.
 */
final class MotoLocationImagePlan
{
    public const MECHANISM = 'loc-shared-reference-v1';

    public const STANDARD = 'standard';

    public const VARIANT = 'variant';

    /** @var string the standard plate of a place with one plate: that plate, by code */
    public const SINGLE_PLATE = 'single_plate';

    /** @var string the standard plate of a place with several plates: the one a person chose in place of the first */
    public const CHOSEN = 'chosen';

    /** @var string the standard plate of a place with several plates while nobody chose another: its first plate in the dossier that may render */
    public const FIRST_PLATE = 'first_plate';

    /** @var string the plates of the set each render on their own: every format before moto-locations-v4 */
    public const INDEPENDENT = 'independent';

    public const READY = 'ready';

    public const NO_STANDARD_PLATE = 'no_standard_plate';

    public const NO_STANDARD_IMAGE = 'no_standard_image';

    public const SOURCE_UNUSABLE = 'source_unusable';

    public const SOURCE_CHANGED = 'source_changed';

    public const ROLE_CHANGED = 'role_changed';

    public const UNPLACED = 'unplaced';

    /** @var array<string, string> the capability the model registry must declare for a role */
    public const CAPABILITY = [self::STANDARD => 'generate', self::VARIANT => 'edit_sources'];

    /** @var array<string, int> the source files a request of a role sends */
    public const SOURCES = [self::STANDARD => 0, self::VARIANT => 1];

    /** @var list<string> the states in which an image chosen for a plate no longer belongs to the plan and its choice is taken back; a source whose file cannot be read blocks the image without taking the choice back */
    public const RELEASING = [self::NO_STANDARD_PLATE, self::NO_STANDARD_IMAGE, self::SOURCE_CHANGED, self::ROLE_CHANGED];

    /** @var list<string> the sentences the instruction of a variant is joined from, as the configuration names them */
    public const SENTENCES = ['keep', 'change', 'no_change', 'keep_light', 'frame', 'continuity'];

    /** @var list<string> what a continuity entry of the dossier may let change between two plates (may_change of MotoLocations 4.0.0) */
    public const MAY_CHANGE = ['camera', 'light', 'time_of_day', 'weather', 'atmosphere', 'ground', 'objects'];

    /** @var array<string, string> why a chosen image is not handed on, as one row of the manifest tells it */
    private const BRIEF = [
        self::NO_STANDARD_PLATE => 'địa điểm chưa có ảnh nền nào dựng được làm ảnh chuẩn',
        self::NO_STANDARD_IMAGE => 'ảnh chuẩn của địa điểm chưa có ảnh được duyệt',
        self::SOURCE_UNUSABLE => 'ảnh chuẩn của địa điểm không dùng được',
        self::SOURCE_CHANGED => 'ảnh đã duyệt dựng từ ảnh chuẩn không còn dùng — render lại từ ảnh chuẩn hiện tại',
        self::ROLE_CHANGED => 'ảnh đã duyệt không khớp kế hoạch ảnh chuẩn hiện tại — render lại',
        self::UNPLACED => 'ảnh nền không thuộc địa điểm nào của bộ',
    ];

    /**
     * @param  array<string, mixed>  $data  the data of a moto-locations-v4 dossier that passed its schema
     * @param  list<string>  $held  the plates a blocking conflict holds back: they render nothing, so none of them is offered as a standard
     * @param  array<string, mixed>  $stored  the standard plates a person chose, by place, as the project keeps them
     * @param  string  $stageId  the Phase 5 stage the dossier is the answer of
     * @param  string  $contentHash  the content hash of that answer
     * @return array<string, array{location: string, name: string, plates: list<string>, single: bool, candidates: list<array{plate: string, order: int, eligible: bool}>, first: ?string, choice: ?array<string, mixed>, standard: ?string, basis: ?string}> the places that have a plate, in the order the dossier lists them, each with its plates in that order. A place with one plate has that plate as its standard, by code. A place with several has, with nobody asked, the first of its plates in the dossier that no conflict holds (first), and the plate a person chose instead once they did: a stored choice counts only while it carries this mechanism, this stage, this content hash, its own place and a plate of that place — a choice made for another set is not read. A place has no standard only while every one of its plates is held
     */
    public static function places(array $data, array $held, array $stored, string $stageId, string $contentHash): array
    {
        $grouped = [];

        foreach (array_values(array_filter((array) ($data['backgrounds'] ?? []), 'is_array')) as $order => $plate) {
            $grouped[(string) ($plate['location'] ?? '')][] = ['order' => $order, 'plate' => $plate];
        }

        $places = [];

        foreach (array_filter((array) ($data['locations'] ?? []), 'is_array') as $location) {
            $id = (string) ($location['id'] ?? '');
            $mine = $grouped[$id] ?? [];

            if ($mine === [] || isset($places[$id])) {
                continue;
            }

            $ids = array_values(array_map(static fn (array $row): string => (string) $row['plate']['id'], $mine));
            $single = count($ids) === 1;
            $candidates = array_values(array_map(static fn (array $row): array => [
                'plate' => (string) $row['plate']['id'],
                'order' => $row['order'],
                'eligible' => ! in_array((string) $row['plate']['id'], $held, true),
            ], $mine));
            $first = array_values(array_filter($candidates, static fn (array $candidate): bool => $candidate['eligible']))[0]['plate'] ?? null;
            $entry = $stored[$id] ?? null;
            $choice = ! $single && is_array($entry)
                && ($entry['mechanism'] ?? null) === self::MECHANISM
                && ($entry['stage_id'] ?? null) === $stageId
                && ($entry['content_hash'] ?? null) === $contentHash
                && ($entry['location'] ?? null) === $id
                && in_array($entry['plate'] ?? null, $ids, true)
                ? $entry
                : null;

            $places[$id] = [
                'location' => $id,
                'name' => (string) ($location['name'] ?? ''),
                'plates' => $ids,
                'single' => $single,
                'candidates' => $candidates,
                'first' => $first,
                'choice' => $choice,
                'standard' => $single ? $ids[0] : ($choice['plate'] ?? $first),
                'basis' => match (true) {
                    $single => self::SINGLE_PLATE,
                    $choice !== null => self::CHOSEN,
                    $first !== null => self::FIRST_PLATE,
                    default => null,
                },
            ];
        }

        return $places;
    }

    /**
     * @param  array<string, array<string, mixed>>  $places  places()
     * @return ?array<string, mixed> the place a plate stands on, null when no place of the dossier lists it
     */
    public static function placeOf(array $places, string $plate): ?array
    {
        foreach ($places as $place) {
            if (in_array($plate, $place['plates'], true)) {
                return $place;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $place  one place of places()
     * @return ?string the role the plan gives the plate: the standard of its place, a variant rendered from the image of that standard, or null while every plate of the place is held and it has no standard — the role is always read from the plan, never from what a request says
     */
    public static function role(array $place, string $plate): ?string
    {
        return match (true) {
            $place['standard'] === null => null,
            $place['standard'] === $plate => self::STANDARD,
            default => self::VARIANT,
        };
    }

    /**
     * @param  string  $location  the place the choice is made for
     * @param  string  $plate  the plate chosen as its standard
     * @param  int  $version  the version of the selection that holds the choice
     * @param  ?string  $actor  who chose
     * @param  string  $at  when, ISO 8601
     * @return array{mechanism: string, stage_id: string, content_hash: string, location: string, plate: string, selection_version: int, chosen_by: ?string, chosen_at: string} the choice as the project keeps it: code metadata only, tied to the stage and content it was made for
     */
    public static function entry(string $stageId, string $contentHash, string $location, string $plate, int $version, ?string $actor, string $at): array
    {
        return [
            'mechanism' => self::MECHANISM,
            'stage_id' => $stageId,
            'content_hash' => $contentHash,
            'location' => $location,
            'plate' => $plate,
            'selection_version' => $version,
            'chosen_by' => $actor,
            'chosen_at' => $at,
        ];
    }

    /**
     * @param  string  $plate  a plate of a set that is not rendered under the mechanism
     * @return array<string, mixed> the verdict of such a plate: it renders on its own, as before, and nothing of the mechanism is asked of it
     */
    public static function independent(string $plate): array
    {
        return ['mechanism' => null, 'location' => null, 'plate' => $plate, 'role' => null, 'standard_plate' => null, 'basis' => null, 'choice_version' => null, 'source' => null, 'state' => self::INDEPENDENT, 'problem' => null, 'brief' => null];
    }

    /**
     * @param  string  $plate  a plate of a set held to the mechanism that no place of its plan lists
     * @param  string  $hold  why the plates of that set are not rendered on their own (MotoLocations::renderHold)
     * @return array<string, mixed> the verdict of such a plate: it has no place, so no role and no render — the hold of its set stands
     */
    public static function unplaced(string $plate, string $hold): array
    {
        return ['mechanism' => self::MECHANISM, 'state' => self::UNPLACED, 'problem' => "{$hold} Ảnh nền {$plate} không thuộc địa điểm nào trong kế hoạch ảnh của bộ đang dùng — không render.", 'brief' => self::BRIEF[self::UNPLACED]] + self::independent($plate);
    }

    /**
     * @param  array<string, mixed>  $place  one place of places()
     * @param  string  $plate  a plate of that place
     * @param  ?array{source: ?array<string, mixed>, chosen?: ?array{artifact_id: string, sha256: string}, state: string, problem: ?string}  $standard  the image in use for the standard plate of the place as it stands now, read only for a variant: the source a new request sends, or why there is none — and under chosen the artifact that is chosen for the standard plate whether or not it can be used as a source (where chosen is not given, the source stands for it)
     * @param  ?array<string, mixed>  $spec  the stored spec of an image of the plate; null when a new request is asked for
     * @return array{mechanism: string, location: string, plate: string, role: ?string, standard_plate: ?string, basis: ?string, choice_version: ?int, source: ?array<string, mixed>, state: string, problem: ?string, brief: ?string} whether the plate may be rendered now and from which source, or — given the spec of an image — whether that image still belongs to the plan: its trace names this mechanism, the role, place and standard plate the plan gives today, a standard was made from no source, and a variant from exactly the artifact chosen for the standard now, compared by artifact id and SHA-256. The comparison comes before anything else is asked of the standard: an image made from another artifact is source_changed — kept, reported and its choice taken back — whether or not the artifact chosen now can be used; source_unusable is answered only when the image was made from the very artifact chosen now and that artifact cannot serve (file gone, hash changed, prompt out of date), which blocks the image and takes no choice back. The source of today is never put in the place of the one an image was made from
     */
    public static function verdict(array $place, string $plate, ?array $standard, ?array $spec = null): array
    {
        $role = self::role($place, $plate);
        $location = (string) $place['location'];
        $base = [
            'mechanism' => self::MECHANISM,
            'location' => $location,
            'plate' => $plate,
            'role' => $role,
            'standard_plate' => $place['standard'],
            'basis' => $place['basis'],
            'choice_version' => is_int($place['choice']['selection_version'] ?? null) ? $place['choice']['selection_version'] : null,
            'source' => null,
        ];
        $stop = static fn (string $state, string $problem): array => $base + ['state' => $state, 'problem' => $problem, 'brief' => self::BRIEF[$state]];

        if ($role === null) {
            return $stop(self::NO_STANDARD_PLATE, "Địa điểm {$location} chưa có ảnh nền nào dựng được để làm ảnh chuẩn (".implode(', ', $place['plates']).' đều đang bị giữ) — không ảnh nào của địa điểm này được dựng.');
        }

        if ($spec !== null) {
            $trace = is_array($spec['reference'] ?? null) ? $spec['reference'] : [];

            if (($trace['mechanism'] ?? null) !== self::MECHANISM) {
                return $stop(self::ROLE_CHANGED, "Ảnh này của {$plate} không được dựng theo cơ chế ảnh chuẩn chung ".self::MECHANISM.' — không dùng cho bộ này; render lại theo kế hoạch ảnh hiện tại.');
            }

            if (($trace['role'] ?? null) !== $role || ($trace['location'] ?? null) !== $location || ($trace['standard_plate'] ?? null) !== $place['standard'] || ($trace['plate'] ?? null) !== $plate) {
                return $stop(self::ROLE_CHANGED, "Ảnh này của {$plate} dựng khi ảnh chuẩn của {$location} là ".json_encode($trace['standard_plate'] ?? null).', với vai '.json_encode($trace['role'] ?? null).". Kế hoạch hiện tại: ảnh chuẩn {$place['standard']}, {$plate} là {$role} — ảnh cũ vẫn được giữ, render lại {$plate} theo kế hoạch hiện tại.");
            }
        }

        $sent = $spec === null ? [] : array_values((array) ($spec['sources'] ?? []));

        if ($role === self::STANDARD) {
            return $sent !== []
                ? $stop(self::ROLE_CHANGED, "Ảnh này của {$plate} dựng từ một ảnh nguồn, trong khi ảnh chuẩn của một địa điểm chỉ dựng từ prompt của chính nó — không dùng làm ảnh chuẩn.")
                : $base + ['state' => self::READY, 'problem' => null, 'brief' => null];
        }

        $chosen = match (true) {
            is_array($standard['chosen'] ?? null) => $standard['chosen'],
            is_array($standard['source'] ?? null) => $standard['source'],
            default => null,
        };

        if ($chosen === null) {
            return $stop($standard['state'] ?? self::NO_STANDARD_IMAGE, (string) ($standard['problem'] ?? "Ảnh chuẩn {$place['standard']} của {$location} chưa có ảnh được duyệt."));
        }

        if ($spec !== null && (count($sent) !== 1 || ($sent[0]['artifact_id'] ?? null) !== ($chosen['artifact_id'] ?? null) || ($sent[0]['sha256'] ?? null) !== ($chosen['sha256'] ?? null))) {
            return $stop(self::SOURCE_CHANGED, "Ảnh này của {$plate} dựng từ một ảnh không còn là ảnh đang dùng của ảnh chuẩn {$place['standard']} — nguồn đã đổi. Ảnh cũ vẫn được giữ cùng lịch sử và chi phí; render lại {$plate} từ ảnh chuẩn hiện tại.");
        }

        if ($standard['problem'] !== null || ! is_array($standard['source'])) {
            return $stop((string) $standard['state'], (string) ($standard['problem'] ?? "Ảnh chuẩn {$place['standard']} của {$location} không dùng làm nguồn được."));
        }

        return ['source' => $standard['source']] + $base + ['state' => self::READY, 'problem' => null, 'brief' => null];
    }

    /**
     * @param  array<string, mixed>  $verdict  verdict() of a plate that may be rendered
     * @param  ?string  $instruction  the fixed words sent before the approved prompt of a variant; a standard is sent none
     * @param  string  $promptSha256  the hash of the approved prompt of the plate
     * @return array{mechanism: string, role: string, location: string, plate: string, standard_plate: string, standard_basis: string, choice_version: ?int, instruction: ?string, instruction_sha256: ?string, request_sha256: string} the trace a render request keeps in its spec beside its sources. The hash of the approved prompt stays the hash of the content; request_sha256 is the hash of what is really sent: the role, the instruction, the approved prompt and the sources in the order sent
     */
    public static function trace(array $verdict, ?string $instruction, string $promptSha256): array
    {
        $role = (string) $verdict['role'];
        $instruction = $role === self::VARIANT ? (string) $instruction : null;
        $sources = $role === self::VARIANT ? [(array) $verdict['source']] : [];

        return [
            'mechanism' => self::MECHANISM,
            'role' => $role,
            'location' => (string) $verdict['location'],
            'plate' => (string) $verdict['plate'],
            'standard_plate' => (string) $verdict['standard_plate'],
            'standard_basis' => (string) $verdict['basis'],
            'choice_version' => $verdict['choice_version'],
            'instruction' => $instruction,
            'instruction_sha256' => $instruction === null ? null : CanonicalJson::hashString($instruction),
            'request_sha256' => self::requestHash($role, $instruction, $promptSha256, $sources),
        ];
    }

    /**
     * @param  ?string  $instruction  the fixed words of a variant request, null for a standard
     * @param  list<array<string, mixed>>  $sources  the sources of the request in the order sent
     * @return string the hash of what a request really sends, apart from the hash of the approved prompt: two requests of one prompt with two sources are two requests
     */
    public static function requestHash(string $role, ?string $instruction, string $promptSha256, array $sources): string
    {
        return CanonicalJson::hash([
            'mechanism' => self::MECHANISM,
            'role' => $role,
            'instruction_sha256' => $instruction === null ? null : CanonicalJson::hashString($instruction),
            'prompt_sha256' => $promptSha256,
            'sources' => array_values(array_map(static fn (array $source): array => array_intersect_key($source, array_flip(['position', 'candidate_id', 'artifact_id', 'sha256'])), $sources)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec  the stored spec of a render request
     * @return ?string why the request must not be sent as it is stored, null when its trace and its sources still say one thing: a standard carries no source, a variant exactly one and its instruction, and the request hash is the hash of that instruction, the approved prompt and those sources. A variant whose source is missing is never sent as a text render
     */
    public static function specProblem(array $spec): ?string
    {
        $trace = is_array($spec['reference'] ?? null) ? $spec['reference'] : [];
        $role = $trace['role'] ?? null;
        $sources = (array) ($spec['sources'] ?? []);

        if (($trace['mechanism'] ?? null) !== self::MECHANISM || ! isset(self::SOURCES[$role])) {
            return 'Ô ảnh không mang đúng dấu vết của cơ chế '.self::MECHANISM.' — không gửi request.';
        }

        if (! array_is_list($sources) || count($sources) !== self::SOURCES[$role]) {
            return $role === self::VARIANT
                ? 'Ảnh nền dựng từ ảnh chuẩn phải mang đúng một ảnh nguồn — không gửi request và không dựng riêng từ prompt.'
                : 'Ảnh chuẩn của một địa điểm không nhận ảnh nguồn — không gửi request.';
        }

        $instruction = $role === self::VARIANT ? ($trace['instruction'] ?? null) : null;

        if ($role === self::VARIANT && (! is_string($instruction) || trim($instruction) === '')) {
            return 'Ảnh nền dựng từ ảnh chuẩn thiếu chỉ dẫn cố định của cơ chế — không gửi request.';
        }

        return hash_equals(self::requestHash((string) $role, $instruction, (string) ($spec['prompt_sha256'] ?? ''), array_values(array_filter($sources, 'is_array'))), (string) ($trace['request_sha256'] ?? ''))
            ? null
            : 'Dấu vết request của ô ảnh không khớp prompt và ảnh nguồn nó mang — không gửi request.';
    }

    /**
     * @param  array{instruction: array<string, string>, changes: array<string, string>, unstated: list<string>}  $words  the fixed words of the mechanism (MotoLocations::sharedReference)
     * @param  array<string, mixed>  $data  the data of the dossier
     * @param  string  $standard  the standard plate of the place, whose image is sent
     * @param  string  $plate  the plate rendered from that image
     * @return string the words sent before the approved prompt of the plate, written as an edit of the attached image: what stays, the one list of what may change, and — where the dossier states the continuity of these two plates — its requirement, verbatim. What may change is the may_change of the first continuity entry that names both plates; where no entry names both, the list the configuration gives for an unstated pair, with the objects added when either plate changes the state of the set. The light of the image is held only while neither the light nor the time of day may change. No word is written here: every sentence is a fixed one or a value the dossier holds
     */
    public static function instruction(array $words, array $data, string $standard, string $plate): string
    {
        $entry = null;

        foreach (array_filter((array) ($data['continuity'] ?? []), 'is_array') as $candidate) {
            $plates = (array) ($candidate['backgrounds'] ?? []);

            if (in_array($standard, $plates, true) && in_array($plate, $plates, true)) {
                $entry = $candidate;

                break;
            }
        }

        if ($entry === null) {
            $restated = array_filter((array) ($data['backgrounds'] ?? []), static fn (mixed $row): bool => is_array($row)
                && in_array($row['id'] ?? null, [$standard, $plate], true)
                && (array) ($row['set_state']['changes'] ?? []) !== []);
            $free = [...$words['unstated'], ...($restated === [] ? [] : ['objects'])];
        } else {
            $free = array_map('strval', (array) ($entry['may_change'] ?? []));
        }

        $named = array_values(array_intersect_key($words['changes'], array_flip($free)));
        $list = count($named) > 1 ? implode(', ', array_slice($named, 0, -1)).' and '.$named[count($named) - 1] : ($named[0] ?? '');
        $requirement = is_string($entry['requirement'] ?? null) ? trim($entry['requirement']) : '';

        return implode(' ', array_filter([
            $words['instruction']['keep'],
            $named === [] ? $words['instruction']['no_change'] : str_replace(':changes', $list, $words['instruction']['change']),
            array_intersect(['light', 'time_of_day'], $free) === [] ? $words['instruction']['keep_light'] : null,
            $words['instruction']['frame'],
            $requirement === '' ? null : str_replace(':requirement', $requirement, $words['instruction']['continuity']),
        ]));
    }

    /**
     * @param  string  $instruction  the words of the mechanism for the plate (instruction())
     * @param  string  $prompt  the approved prompt of the target plate, whole
     * @return string what a variant request sends as its text: the instruction, then the approved prompt unchanged
     */
    public static function requestPrompt(string $instruction, string $prompt): string
    {
        return trim($instruction)."\n\n".$prompt;
    }

    /**
     * @param  array<string, mixed>  $entry  one entry of the model registry for the Phase 5 render task
     * @param  string  $role  the role of the plate asked for
     * @return ?string why the model cannot serve the role, null when the registry declares what the role needs: generate for a standard; edit_sources with room for every source for a variant. No other model is ever taken in its place
     */
    public static function modelProblem(array $entry, string $role): ?string
    {
        $label = (string) ($entry['label'] ?? $entry['id'] ?? '');
        $needs = self::SOURCES[$role];

        if (! in_array(self::CAPABILITY[$role], (array) ($entry['capabilities'] ?? []), true)) {
            return $role === self::VARIANT
                ? "Model {$label} không khai năng lực edit_sources (dựng từ ảnh nguồn) cho Phase 5 — ảnh nền này phải dựng từ ảnh chuẩn của địa điểm. Chọn model có khai edit_sources; hệ thống không tự đổi model."
                : "Model {$label} không khai năng lực generate cho Phase 5.";
        }

        if ($needs > 0 && (! is_int($entry['max_sources'] ?? null) || $entry['max_sources'] < $needs)) {
            return "Model {$label} không khai nhận đủ {$needs} ảnh nguồn (max_sources) cho Phase 5 — chọn model khác; hệ thống không tự đổi model.";
        }

        return null;
    }
}
