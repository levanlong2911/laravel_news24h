<?php

declare(strict_types=1);

namespace App\Video\Moto\Film;

use App\Video\FinalComposition\CompositionMeasure;
use App\Video\Superyacht\Support\CanonicalJson;

/**
 * The QC report of one cut film: what code measured on its file, what a reviewer said of it, what nobody checked, and every
 * finding with what became of it. Pure: no database, no file, no provider. A report is a document about one file and one
 * film plan; every change — a measurement, a review, a finding raised or settled — gives a new document, and the caller
 * stores each one, so no report is ever rewritten.
 *
 * Code counts and compares here: stretches against what the film plan declared, verdicts against the areas the policy
 * requires, severities against the ones that block. Whether a stretch is a fault and whether the film works are a person's.
 * The technical result and the content review stay two results; nothing here folds them into one pass.
 */
final class MotoFilmQcReport
{
    public const VERSION = 'moto-film-qc-builder-v1';

    public const FORMAT = 'moto-film-qc-report-v1';

    public const SCHEMA = 'MotoFilmQCReport@1.0.0';

    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const NOT_CHECKED = 'not_checked';

    /** @var list<string> */
    public const VERDICTS = [self::PASS, self::FAIL, self::NOT_CHECKED];

    /** @var list<string> every status a finding may have */
    public const STATUSES = ['new', 'fixed', 'rechecked', 'accepted', 'false_positive'];

    /** @var list<string> the statuses of a finding that is settled: looked at again, left as it is with a reason, or not a fault. One only said to be fixed is still open */
    public const SETTLED = ['rechecked', 'accepted', 'false_positive'];

    /** @var array<string, array{0: string, 1: string}> what code measures: the kind of the finding it raises and how the stretch is worded */
    private const STRETCHES = [
        'black' => ['black_stretch', 'Hình đen'],
        'freeze' => ['frozen_picture', 'Hình đứng yên'],
        'silence' => ['silent_stretch', 'Tiếng im lặng'],
    ];

    /**
     * The report of a film as it measures now. The technical part is made anew from the measurement; the reviews, the findings
     * people raised and what was settled are carried over from the report before, which was of the same file.
     *
     * @param  array{id: string, revision: int, purpose: ?string, artifact_sha256: string, bytes: int, film_plan_hash: string, edit_plan_hash: ?string, file_matches: bool}  $final  the film and its file; file_matches says whether the file on the disk hashes to the receipt of the cut
     * @param  array<string, mixed>  $plan  the film plan the film was cut from
     * @param  ?array<string, mixed>  $scope  MotoFilmScope::of() frozen with the film; null for a film cut before it was kept
     * @param  array{phase_id: string, phase_revision: int, policy_hash: string}  $contract
     * @param  array<string, mixed>  $policy  the QC policy
     * @param  array<string, mixed>  $measured  CompositionMeasure::measure()
     * @param  ?array<string, mixed>  $previous  the report before, of the same file
     * @return array<string, mixed>
     */
    public static function measured(string $projectId, array $final, array $plan, ?array $scope, array $contract, array $policy, array $measured, ?string $ffmpeg, ?array $previous): array
    {
        $declared = self::declared($plan);
        $ok = ($measured['ok'] ?? false) === true;
        $stretches = [];
        $suspects = [];

        foreach (array_keys(self::STRETCHES) as $check) {
            $stretches[$check] = [];

            foreach ($ok ? (array) $measured[$check] : [] as $stretch) {
                $known = self::within((int) $stretch['start_ms'], (int) $stretch['end_ms'], $declared[$check] ?? []);
                $stretches[$check][] = ['start_ms' => (int) $stretch['start_ms'], 'end_ms' => (int) $stretch['end_ms'], 'declared' => $known];

                if (! $known) {
                    $suspects[] = [$check, (int) $stretch['start_ms'], (int) $stretch['end_ms']];
                }
            }
        }

        $loudness = ['integrated_lufs' => null, 'range_lu' => null, 'true_peak_dbfs' => null];

        foreach ($ok ? array_keys($loudness) : [] as $key) {
            $value = $measured['loudness'][$key] ?? null;
            $loudness[$key] = is_int($value) || is_float($value) ? (float) $value : null;
        }

        $missing = count((array) ($scope['missing'] ?? []));
        $checks = [
            self::check('file', $final['file_matches'] ? self::PASS : self::FAIL, $final['file_matches'] ? 'File trên đĩa đúng hash đã ghi lúc dựng.' : 'File trên đĩa không còn hoặc khác hash đã ghi lúc dựng.'),
            self::check('cut', self::PASS, 'Lúc dựng đã kiểm: '.(int) $plan['timeline']['total_frames'].' khung ở '.(int) $plan['timeline']['fps'].' fps, '.(int) $plan['output']['width'].'×'.(int) $plan['output']['height'].', một luồng hình và một luồng tiếng đúng thời lượng timeline.'),
            match (true) {
                $scope === null => self::check('scope', self::NOT_CHECKED, 'Bản này dựng trước khi phạm vi shot được lưu cùng phim.'),
                $missing === 0 => self::check('scope', self::PASS, 'Phim có đủ '.(int) $scope['expected'].' shot của kế hoạch shot.'),
                $final['purpose'] === MotoFilmScope::PREVIEW => self::check('scope', 'measured', 'Bản dựng thử dùng '.(int) $scope['used'].'/'.(int) $scope['expected'].' shot.'),
                default => self::check('scope', self::FAIL, 'Phim dùng '.(int) $scope['used'].'/'.(int) $scope['expected'].' shot của kế hoạch shot.'),
            },
            self::check('measure', $ok ? self::PASS : self::FAIL, $ok ? 'Đã giải mã hết file để đo.' : 'Không đo được file: '.(string) ($measured['error'] ?? 'không rõ').'.'),
        ];

        foreach (self::STRETCHES as $check => [, $label]) {
            $found = count($stretches[$check]);
            $open = count(array_filter($stretches[$check], static fn (array $stretch): bool => ! $stretch['declared']));
            $checks[] = match (true) {
                ! $ok => self::check($check, self::NOT_CHECKED, 'Chưa đo.'),
                $open > 0 => self::check($check, 'suspect', "{$label}: {$open} đoạn ngoài phần kế hoạch phim đã khai".($found > $open ? ', '.($found - $open).' đoạn đã khai' : '').'.'),
                default => self::check($check, self::PASS, $found === 0 ? "{$label}: không có đoạn nào." : "{$label}: {$found} đoạn, đều là phần kế hoạch phim đã khai."),
            };
        }

        $checks[] = ! $ok ? self::check('loudness', self::NOT_CHECKED, 'Chưa đo.') : self::check('loudness', 'measured', sprintf(
            'Loudness %s LUFS, dải %s LU, peak %s dBFS — chính sách không đặt ngưỡng, số đo chỉ để xem.',
            self::shown($loudness['integrated_lufs']), self::shown($loudness['range_lu']), self::shown($loudness['true_peak_dbfs']),
        ));

        $failed = array_filter($checks, static fn (array $check): bool => $check['status'] === self::FAIL);
        $report = [
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'final' => array_diff_key($final, ['file_matches' => true]),
            'policy' => ['phase_id' => (string) $contract['phase_id'], 'phase_revision' => (int) $contract['phase_revision'], 'policy_hash' => (string) $contract['policy_hash']],
            'tools' => ['report' => self::VERSION, 'measure' => CompositionMeasure::VERSION, 'ffmpeg' => $ffmpeg],
            'technical' => [
                'status' => match (true) {
                    ! $ok && $final['file_matches'] => 'error',
                    $failed !== [] => self::FAIL,
                    default => self::PASS,
                },
                'checks' => $checks,
                'measured' => ['duration_ms' => (int) $plan['timeline']['total_ms'], 'frames' => (int) $plan['timeline']['total_frames']] + $stretches + ['loudness' => $loudness],
            ],
            'content' => ['status' => 'not_reviewed', 'areas' => [], 'reviews' => array_values((array) ($previous['content']['reviews'] ?? []))],
            'not_checked' => [],
            'findings' => self::technicalFindings($suspects, $plan, $policy, array_values((array) ($previous['findings'] ?? []))),
        ];

        return self::summed($report, $policy);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array{area: string, verdict: string, note: ?string}>  $verdicts  what the reviewer said, for the areas they spoke of
     * @return array<string, mixed> the report with that review added; an area the review leaves not checked keeps the verdict it had
     */
    public static function reviewed(array $report, array $policy, array $verdicts, ?string $reviewerId, string $decisionId): array
    {
        $report['content']['reviews'][] = [
            'id' => sprintf('RV%03d', count((array) $report['content']['reviews']) + 1),
            'reviewer' => ['kind' => 'person', 'id' => $reviewerId],
            // A person reviewing on the screen has the file itself in front of them: its picture, its sound and what the page lists of it.
            'evidence' => ['metadata' => true, 'frames' => true, 'video' => true, 'audio' => true],
            'verdicts' => array_values($verdicts),
            'decision_id' => $decisionId,
        ];

        return self::summed($report, $policy);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{area: ?string, start_ms: ?int, end_ms: ?int, severity: string, description: string, suggestion: ?string}  $finding  what a person found
     * @return array<string, mixed> the report with that finding added, new
     */
    public static function raised(array $report, array $policy, array $finding, ?string $reviewerId): array
    {
        $report['findings'][] = [
            'id' => self::nextFindingId((array) $report['findings']),
            'source' => 'content',
            'raised_by' => ['kind' => 'person', 'id' => $reviewerId],
            'check' => null,
            'area' => $finding['area'],
            'start_ms' => $finding['start_ms'],
            'end_ms' => $finding['end_ms'],
            'shots' => [],
            'kind' => 'review',
            'severity' => $finding['severity'],
            'description' => $finding['description'],
            'evidence' => null,
            'suggestion' => $finding['suggestion'],
            'status' => 'new',
            'resolution' => null,
        ];

        return self::summed($report, $policy);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed> the report with the finding in that status, with who settled it and why
     *
     * @throws \InvalidArgumentException when the report has no such finding
     */
    public static function settled(array $report, array $policy, string $findingId, string $status, string $reason, ?string $by, string $decisionId): array
    {
        foreach ($report['findings'] as $i => $finding) {
            if ($finding['id'] === $findingId) {
                $report['findings'][$i]['status'] = $status;
                $report['findings'][$i]['resolution'] = ['by' => $by, 'reason' => $reason, 'decision_id' => $decisionId];

                return self::summed($report, $policy);
            }
        }

        throw new \InvalidArgumentException("The report has no finding {$findingId}.");
    }

    /** @param array<string, mixed> $report */
    public static function hash(array $report): string
    {
        return CanonicalJson::hash(['qc_report' => $report]);
    }

    /**
     * @param  ?array<string, mixed>  $report  the latest report of the film, null when it has none
     * @param  array{phase_id: string, phase_revision: int, policy_hash: string}  $contract  the QC policy in force now, by its revision and hash
     * @param  array<string, mixed>  $policy  the QC policy in force now
     * @param  array<string, string>  $labels  how each area is worded for a person
     * @return ?string why the film may not be approved on its QC: no report of this very file, a report made under another QC policy than the one in force, a technical result that is not a pass, an area the policy in force requires that is not passed, or a blocking finding still open; null when nothing of the QC holds it back
     */
    public static function approvalProblem(?array $report, array $contract, array $policy, string $sha256, string $filmPlanHash, array $labels = []): ?string
    {
        if ($report === null) {
            return 'Bản này chưa có báo cáo QC — bấm Kiểm QC.';
        }

        if (! hash_equals((string) $report['final']['artifact_sha256'], $sha256) || ! hash_equals((string) $report['final']['film_plan_hash'], $filmPlanHash)) {
            return 'Báo cáo QC đang có là của file hoặc kế hoạch khác — bấm Kiểm QC để kiểm đúng file này.';
        }

        // A report answers the policy it was made under: what was measured, with which thresholds, and which areas had to be reviewed. Under another policy it answers other questions, so it is made again; the film itself is not cut again.
        if (! self::under($report, $contract)) {
            return 'Báo cáo QC đang có được lập theo chính sách QC cũ (revision '.(int) ($report['policy']['phase_revision'] ?? 0).', hiện hành là revision '.(int) $contract['phase_revision'].') — bấm Kiểm QC để lập báo cáo theo chính sách hiện hành; không cần dựng lại phim.';
        }

        if ($report['technical']['status'] !== self::PASS) {
            $failed = array_values(array_filter((array) $report['technical']['checks'], static fn (array $check): bool => $check['status'] === self::FAIL));

            return 'QC kỹ thuật chưa đạt: '.($failed[0]['detail'] ?? 'chưa đo được file').' Không duyệt được.';
        }

        // What must be passed is what the policy in force requires, read from that policy and never from the list a report carries.
        $verdicts = array_column((array) $report['content']['areas'], 'verdict', 'area');
        $open = [];

        foreach ((array) $policy['content']['required'] as $area) {
            $verdict = $verdicts[$area] ?? self::NOT_CHECKED;

            if ($verdict !== self::PASS) {
                $open[] = ($labels[$area] ?? $area).' — '.($verdict === self::FAIL ? 'không đạt' : 'chưa kiểm');
            }
        }

        if ($open !== []) {
            return 'QC nội dung chưa đủ: '.implode('; ', $open).'. Review các mục bắt buộc trước khi duyệt.';
        }

        $blocking = self::blocking($report, $policy);

        if ($blocking !== []) {
            return 'Còn '.count($blocking).' finding nghiêm trọng chưa xử lý ('.implode(', ', array_column($blocking, 'id')).') — xử lý từng finding trước khi duyệt.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{phase_id: string, phase_revision: int, policy_hash: string}  $contract  a QC policy, by its revision and hash
     * @return bool whether the report was made under that very policy: the same revision row and the same policy text
     */
    public static function under(array $report, array $contract): bool
    {
        $pin = (array) ($report['policy'] ?? []);

        return ($pin['phase_id'] ?? null) === (string) $contract['phase_id']
            && (int) ($pin['phase_revision'] ?? 0) === (int) $contract['phase_revision']
            && hash_equals((string) $contract['policy_hash'], (string) ($pin['policy_hash'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>> the findings that hold an approval back: of a blocking severity and not settled
     */
    public static function blocking(array $report, array $policy): array
    {
        $severities = (array) $policy['findings']['blocking'];

        return array_values(array_filter((array) $report['findings'], static fn (array $finding): bool => in_array($finding['severity'], $severities, true) && ! in_array($finding['status'], self::SETTLED, true)));
    }

    /**
     * @param  array<string, mixed>  $plan  a film plan
     * @return array{black: list<array{0: int, 1: int}>, silence: list<array{0: int, 1: int}>, freeze: list<array{0: int, 1: int}>} what the film plan declares, in milliseconds of the film: black where a dip fades a clip out and the next one in, silence where a clip has no sound of its own; the plan holds no frame on purpose
     */
    public static function declared(array $plan): array
    {
        $fps = (int) $plan['timeline']['fps'];
        $ms = static fn (int $frames): int => (int) round($frames * 1000 / $fps);
        $black = [];
        $silence = [];

        foreach (array_values((array) $plan['clips']) as $clip) {
            $start = (int) $clip['timeline_start_frame'];
            $end = $start + (int) $clip['duration_frames'];

            if ((int) $clip['fade_in_frames'] > 0) {
                $black[] = [$ms($start), $ms($start + (int) $clip['fade_in_frames'])];
            }

            if ((int) $clip['fade_out_frames'] > 0) {
                $black[] = [$ms($end - (int) $clip['fade_out_frames']), $ms($end)];
            }

            if (($clip['audio'] ?? null) === 'silence') {
                $silence[] = [$ms($start), $ms($end)];
            }
        }

        return ['black' => self::merged($black), 'silence' => self::merged($silence), 'freeze' => []];
    }

    /**
     * The parts of a report that are counted from the rest: the verdict in force for every area, how far the review has come and what nobody checked.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function summed(array $report, array $policy): array
    {
        $required = (array) $policy['content']['required'];
        $areas = [];

        foreach ((array) $policy['content']['areas'] as $area) {
            $verdict = self::NOT_CHECKED;
            $review = null;

            // The verdict in force is the one of the latest review that gave the area one.
            foreach ((array) $report['content']['reviews'] as $entry) {
                foreach ((array) $entry['verdicts'] as $given) {
                    if ($given['area'] === $area && $given['verdict'] !== self::NOT_CHECKED) {
                        $verdict = $given['verdict'];
                        $review = $entry['id'];
                    }
                }
            }

            $areas[] = ['area' => (string) $area, 'required' => in_array($area, $required, true), 'verdict' => $verdict, 'review' => $review];
        }

        $needed = array_filter($areas, static fn (array $area): bool => $area['required']);
        $given = array_filter($needed, static fn (array $area): bool => $area['verdict'] !== self::NOT_CHECKED);
        $report['content']['areas'] = $areas;
        $report['content']['status'] = match (true) {
            $given === [] && array_filter($areas, static fn (array $area): bool => $area['verdict'] !== self::NOT_CHECKED) === [] => 'not_reviewed',
            count($given) === count($needed) => 'reviewed',
            default => 'partly_reviewed',
        };
        $report['not_checked'] = [
            ...array_column(array_filter((array) $report['technical']['checks'], static fn (array $check): bool => $check['status'] === self::NOT_CHECKED), 'code'),
            ...array_column(array_filter($areas, static fn (array $area): bool => $area['verdict'] === self::NOT_CHECKED), 'area'),
        ];

        return $report;
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $suspects  the stretches the film plan did not declare
     * @param  list<array<string, mixed>>  $before  the findings of the report before
     * @return list<array<string, mixed>> the findings of the report: the ones people raised, as they were, then one for every suspect stretch; a stretch found before keeps its id and what was settled about it, a stretch no longer found is not reported again
     */
    private static function technicalFindings(array $suspects, array $plan, array $policy, array $before): array
    {
        $known = [];

        foreach ($before as $finding) {
            if ($finding['source'] === 'technical') {
                $known[$finding['check'].'|'.$finding['start_ms'].'|'.$finding['end_ms']] = $finding;
            }
        }

        $findings = array_values(array_filter($before, static fn (array $finding): bool => $finding['source'] !== 'technical'));
        $all = $before;

        foreach ($suspects as [$check, $start, $end]) {
            $earlier = $known[$check.'|'.$start.'|'.$end] ?? null;

            if ($earlier !== null) {
                // A stretch nobody settled yet takes the severity of the policy the report is made under now; one a person settled stays as they left it.
                $findings[] = $earlier['status'] === 'new' ? array_replace($earlier, ['severity' => (string) $policy['technical']['suspect_severity']]) : $earlier;

                continue;
            }

            [$kind, $label] = self::STRETCHES[$check];
            $finding = [
                'id' => self::nextFindingId($all),
                'source' => 'technical',
                'raised_by' => ['kind' => 'code', 'id' => null],
                'check' => $check,
                'area' => null,
                'start_ms' => $start,
                'end_ms' => $end,
                'shots' => self::shotsAt($plan, $start, $end),
                'kind' => $kind,
                'severity' => (string) $policy['technical']['suspect_severity'],
                'description' => $label.' từ '.self::clock($start).' đến '.self::clock($end).' ('.($end - $start).' ms), ngoài phần kế hoạch phim đã khai. Cần người xem: chủ ý hay lỗi.',
                'evidence' => 'Đo trên file bằng '.CompositionMeasure::VERSION.'.',
                'suggestion' => null,
                'status' => 'new',
                'resolution' => null,
            ];
            $findings[] = $finding;
            $all[] = $finding;
        }

        usort($findings, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $findings;
    }

    /** @param list<array<string, mixed>> $findings */
    private static function nextFindingId(array $findings): string
    {
        $last = 0;

        foreach ($findings as $finding) {
            $last = max($last, (int) substr((string) $finding['id'], 2));
        }

        return sprintf('FD%03d', $last + 1);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string> the shots on the film in that stretch
     */
    private static function shotsAt(array $plan, int $start, int $end): array
    {
        $shots = [];

        foreach ((array) $plan['clips'] as $clip) {
            if ($start < (int) $clip['timeline_end_ms'] && $end > (int) $clip['timeline_start_ms']) {
                $shots[] = (string) $clip['shot_id'];
            }
        }

        return $shots;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     * @return bool whether the stretch lies inside one declared window; a stretch may stand one frame out on either side, since a filter reports the first and the last frame it saw
     */
    private static function within(int $start, int $end, array $windows): bool
    {
        foreach ($windows as [$from, $to]) {
            if ($start >= $from - 50 && $end <= $to + 50) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     * @return list<array{0: int, 1: int}> the windows in order, the ones that touch joined into one
     */
    private static function merged(array $windows): array
    {
        usort($windows, static fn (array $a, array $b): int => $a <=> $b);
        $merged = [];

        foreach ($windows as $window) {
            $last = count($merged) - 1;

            if ($last >= 0 && $window[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $window[1]);
            } else {
                $merged[] = $window;
            }
        }

        return $merged;
    }

    /** @return array{code: string, status: string, detail: string} */
    private static function check(string $code, string $status, string $detail): array
    {
        return ['code' => $code, 'status' => $status, 'detail' => $detail];
    }

    private static function clock(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, ',', ''), '0'), ',').' s';
    }

    private static function shown(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
