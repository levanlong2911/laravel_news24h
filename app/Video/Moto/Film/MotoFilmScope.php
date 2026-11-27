<?php

declare(strict_types=1);

namespace App\Video\Moto\Film;

/**
 * What of the approved shot plan a film holds: how many shots the plan has, how many the film uses, and every shot it
 * leaves out with why. A film is cut for one of two purposes — a preview, which may leave shots out and is never
 * approved for release, or a final, which holds every shot of the plan.
 *
 * Only ids and counts are compared here: whether a shot matters to the story is not judged.
 */
final class MotoFilmScope
{
    public const VERSION = 'moto-film-scope-v1';

    public const PREVIEW = 'preview';

    public const FINAL = 'final';

    /** @var list<string> */
    public const PURPOSES = [self::PREVIEW, self::FINAL];

    /** A shot whose clip no reviewer approved yet. */
    public const NOT_APPROVED = 'clip_not_approved';

    /** A shot whose approved clip lost its file, or whose file changed. */
    public const FILE = 'clip_file';

    /** A shot whose approved clip no longer fits the sources the film is cut from. */
    public const SOURCE = 'clip_source';

    /** @var array<string, array{0: string, 1: string}> the cause the Phase 10 manifest gives for a missing shot: its group and how the screen words it */
    private const CAUSES = [
        'not_approved' => [self::NOT_APPROVED, 'chưa có clip được duyệt'],
        'file_missing' => [self::FILE, 'file clip đã duyệt không còn trên đĩa'],
        'file_hash_mismatch' => [self::FILE, 'file clip đã duyệt đã bị đổi nội dung'],
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  the shots of the approved shot plan, in plan order
     * @param  array<string, mixed>  $manifest  the Phase 10 manifest: the shots it lists as missing and why
     * @param  list<string>  $used  the ids of the shots the film holds
     * @return array{version: string, expected: int, used: int, complete: bool, missing: list<array{shot_id: string, scene_id: ?string, order: ?int, reason: string, cause: string, message: string}>}
     */
    public static function of(array $rows, array $manifest, array $used): array
    {
        $problems = (array) ($manifest['problems'] ?? []);
        $missing = [];
        $planned = [];

        foreach (array_values($rows) as $index => $row) {
            $shotId = (string) $row['id'];
            $planned[$shotId] = true;

            if (! in_array($shotId, $used, true)) {
                $missing[] = self::left($shotId, isset($row['scene']) ? (string) $row['scene'] : null, $index + 1, isset($problems[$shotId]) ? (string) $problems[$shotId] : null);
            }
        }

        // A shot the manifest reports that the shot plan does not have is still shown: nothing reported is dropped.
        foreach ((array) ($manifest['missing'] ?? []) as $shotId) {
            if (! isset($planned[(string) $shotId])) {
                $missing[] = self::left((string) $shotId, null, null, isset($problems[$shotId]) ? (string) $problems[$shotId] : null);
            }
        }

        return [
            'version' => self::VERSION,
            'expected' => count($rows),
            'used' => count(array_unique($used)),
            'complete' => $missing === [],
            'missing' => $missing,
        ];
    }

    /** @return string how a cause of the Phase 10 manifest is worded; null stands for a shot the manifest holds no clip for */
    public static function wording(?string $cause): string
    {
        return match (true) {
            $cause === null => 'manifest Phase 10 không có clip cho shot này',
            isset(self::CAUSES[$cause]) => self::CAUSES[$cause][1],
            default => 'clip đã duyệt không còn khớp kế hoạch ('.$cause.')',
        };
    }

    /**
     * @param  array<string, mixed>  $scope  of()
     * @return ?string why a final cannot be cut from this plan: it leaves shots of the approved shot plan out
     */
    public static function finalProblem(array $scope): ?string
    {
        if (($scope['complete'] ?? false) === true) {
            return null;
        }

        return 'Chưa dựng được bản final: '.self::summary($scope).' Bản final phải có đủ mọi shot của kế hoạch shot đã duyệt — duyệt đủ clip ở màn Clips, hoặc bấm Dựng thử để xem trước phần đã có.';
    }

    /**
     * @param  ?string  $purpose  the purpose the film was cut for; null for a film cut before a request named one
     * @param  ?array<string, mixed>  $scope  of() for the plan in force, which is the plan the film was cut from
     * @return ?string why this film may not be approved for release
     */
    public static function approvalProblem(?string $purpose, ?array $scope): ?string
    {
        if ($purpose === self::PREVIEW) {
            return 'Bản này là bản dựng thử — không duyệt xuất bản. Dựng bản final rồi duyệt bản đó.';
        }

        if ($purpose !== null && $purpose !== self::FINAL) {
            return 'Bản này ghi mục đích dựng không hợp lệ ('.$purpose.') — không duyệt được.';
        }

        if ($scope === null || ! isset($scope['complete'])) {
            return 'Không đọc được phạm vi shot của kế hoạch phim — không duyệt được.';
        }

        return $scope['complete'] === true ? null : 'Phim này chưa đủ kế hoạch shot đã duyệt: '.self::summary($scope).' Không duyệt xuất bản một bản thiếu shot.';
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return string the counts and the first shots left out, as a sentence
     */
    public static function summary(array $scope): string
    {
        $missing = (array) ($scope['missing'] ?? []);
        $named = array_map(static fn (array $shot): string => $shot['shot_id'].' ('.self::wording($shot['cause'] === 'not_in_manifest' ? null : $shot['cause']).')', array_slice($missing, 0, 5));

        return sprintf(
            'phim dùng %d/%d shot, còn thiếu %d: %s%s.',
            (int) ($scope['used'] ?? 0),
            (int) ($scope['expected'] ?? 0),
            count($missing),
            implode('; ', $named),
            count($missing) > 5 ? ' và '.(count($missing) - 5).' shot khác' : '',
        );
    }

    /** @return array{shot_id: string, scene_id: ?string, order: ?int, reason: string, cause: string, message: string} */
    private static function left(string $shotId, ?string $sceneId, ?int $order, ?string $cause): array
    {
        return [
            'shot_id' => $shotId,
            'scene_id' => $sceneId,
            'order' => $order,
            // A cause the manifest does not name, or one that is neither an approval nor a file, is a clip that does not fit its sources.
            'reason' => $cause === null ? self::SOURCE : (self::CAUSES[$cause][0] ?? self::SOURCE),
            'cause' => $cause ?? 'not_in_manifest',
            'message' => 'Shot '.$shotId.' không lên phim: '.self::wording($cause).'.',
        ];
    }
}
