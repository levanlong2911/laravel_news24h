<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use RuntimeException;

/**
 * What a film is cut from: the approved shot plan, the Phase 10 manifest of approved clips and the cards of the script the shot plan pins.
 * The script is the exact one the shot plan was made from, found through its scene plan, never the script selected now.
 * Nothing here changes the shot plan or what code derives from it; a source that does not hold is reported as a blocker.
 */
final class MotoFilmSourceService
{
    public function __construct(
        private readonly MotoClipSourceService $shots,
        private readonly MotoClipSelectionService $selection,
        private readonly ScriptSourceService $scripts,
    ) {}

    /**
     * @return array{workflow: string, action: string, settings: array<string, mixed>} the film flow of the project's workflow
     *
     * @throws RuntimeException when the project does not cut its film by the phase-11 policy
     */
    public function workflowFor(string $projectId): array
    {
        $project = VideoProject::query()->with('article.category')->find($projectId) ?? throw new RuntimeException('Không tìm thấy dự án.');
        $workflow = (string) (config('video.superyacht.contracts.categories')[$project->article?->category?->slug ?? ''] ?? '');
        $entry = $workflow === '' ? null : (config('video.films.workflows')[$workflow] ?? null);

        if (! is_array($entry)) {
            throw new RuntimeException('Dự án này không dựng final theo chính sách Phase 11.');
        }

        return ['workflow' => $workflow, 'action' => (string) $entry['action'], 'settings' => $entry];
    }

    public function supports(string $projectId): bool
    {
        try {
            $this->workflowFor($projectId);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{shots: array<string, mixed>, manifest: array<string, mixed>, script: ?array{stage_id: string, content_hash: string, scenes_stage_id: string}, cards: list<array<string, mixed>>, blockers: list<array{code: string, subject: string, message: string}>}
     *
     * @throws RuntimeException when the shot plan or the clip plan cannot be read at all
     */
    public function source(string $projectId): array
    {
        $manifest = $this->selection->manifest($projectId);
        $shots = $this->shots->source($projectId);
        $blockers = [];

        if ($manifest['source']['token'] != $shots['token']) {
            $blockers[] = self::blocker('source_changed', 'shots', 'Kế hoạch shot đã đổi trong lúc đọc nguồn — tải lại trang.');
        }

        [$script, $cards, $problems] = $this->cards($projectId, $shots);

        return ['shots' => $shots, 'manifest' => $manifest, 'script' => $script, 'cards' => $cards, 'blockers' => [...$blockers, ...$problems]];
    }

    /**
     * Every card of the pinned script, by its place in the script, on the film's time: the start of its beat on the shot plan's timing plus its offset.
     * The copy each beat of the shot plan carries must be the same card at the same time, or the shot plan was not made from this script.
     *
     * @param  array<string, mixed>  $shots  MotoClipSourceService::source()
     * @return array{0: ?array{stage_id: string, content_hash: string, scenes_stage_id: string}, 1: list<array<string, mixed>>, 2: list<array{code: string, subject: string, message: string}>}
     */
    private function cards(string $projectId, array $shots): array
    {
        $stage = VideoPlanningStage::query()->withoutSnapshot()->find($shots['stage_id']);
        $pin = (array) (PlanningStageStore::metadataOf(is_array($stage?->input_json) ? $stage->input_json : null)['source']['scenes'] ?? []);
        $scenes = is_string($pin['stage_id'] ?? null) ? VideoPlanningStage::query()->withoutSnapshot()
            ->whereKey($pin['stage_id'])
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCENES->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first() : null;
        $token = (array) (PlanningStageStore::metadataOf(is_array($scenes?->input_json) ? $scenes->input_json : null)['source']['script'] ?? []);

        if ($scenes === null || ! is_string($token['stage_id'] ?? null) || ! is_string($token['content_hash'] ?? null)) {
            return [null, [], [self::blocker('script_unpinned', 'script', 'Kế hoạch shot không ghim được kịch bản qua kế hoạch scene của nó — không biết card lấy từ bản nào.')]];
        }

        $script = $this->scripts->scriptStage($projectId, $token['stage_id']);
        [$evaluation, $problem] = $script === null ? [[], 'Bản kịch bản kế hoạch shot ghim không còn hoặc không thuộc dự án.'] : $this->scripts->inspect($projectId, $script);

        if ($problem !== null || ! hash_equals((string) $token['content_hash'], (string) ($evaluation['content_hash'] ?? ''))) {
            return [null, [], [self::blocker('script_changed', 'script', $problem ?? 'Nội dung bản kịch bản ghim không còn khớp hash kế hoạch scene đã ghim.')]];
        }

        $pinned = ['stage_id' => (string) $script->id, 'content_hash' => (string) $evaluation['content_hash'], 'scenes_stage_id' => (string) $scenes->id];
        $beats = [];

        foreach ((array) ($shots['context']['beats'] ?? []) as $beat) {
            $beats[(string) ($beat['id'] ?? '')] = $beat;
        }

        $cards = [];
        $problems = [];
        $seen = [];

        foreach (array_values((array) ($evaluation['document']['data']['text_cards'] ?? [])) as $index => $card) {
            $path = '/data/text_cards/'.$index;
            $subject = $pinned['stage_id'].'#'.$path;
            $beat = $beats[(string) ($card['beat'] ?? '')] ?? null;
            $offset = self::milliseconds($card['offset_s'] ?? null);
            $length = self::milliseconds($card['sec'] ?? null);

            if ($beat === null || ! is_int($beat['start_ms'] ?? null)) {
                $problems[] = self::blocker('card_beat_unknown', $subject, 'Card '.$path.' thuộc beat '.json_encode($card['beat'] ?? null).' mà kế hoạch shot không có.');

                continue;
            }

            if ($offset === null || $length === null || $length < 1) {
                $problems[] = self::blocker('card_time_not_exact', $subject, 'Card '.$path.' có offset_s/sec không quy đúng ra mili giây — không làm tròn.');

                continue;
            }

            $position = $seen[$beat['id']] = ($seen[$beat['id']] ?? -1) + 1;
            $copy = array_values((array) ($beat['cards'] ?? []))[$position] ?? null;

            if (! is_array($copy) || ($copy['type'] ?? null) !== ($card['type'] ?? null) || ($copy['text'] ?? null) !== ($card['text'] ?? null)
                || ($copy['offset_ms'] ?? null) !== $offset || ($copy['duration_ms'] ?? null) !== $length) {
                $problems[] = self::blocker('card_source_mismatch', $subject, 'Card '.$path.' của kịch bản ghim không khớp bản beat '.$beat['id'].' của kế hoạch shot mang theo.');

                continue;
            }

            $start = (int) $beat['start_ms'] + $offset;
            $cards[] = [
                'source_card_id' => $subject,
                'source_path' => $path,
                'source_script_stage_id' => $pinned['stage_id'],
                'beat' => (string) $beat['id'],
                'type' => (string) $card['type'],
                'text' => (string) $card['text'],
                'start_ms' => $start,
                'end_ms' => $start + $length,
            ];
        }

        foreach ($beats as $id => $beat) {
            if (count((array) ($beat['cards'] ?? [])) !== ($seen[$id] ?? -1) + 1) {
                $problems[] = self::blocker('card_source_mismatch', 'beat '.$id, 'Beat '.$id.' của kế hoạch shot mang '.count((array) ($beat['cards'] ?? [])).' card, kịch bản ghim có '.(($seen[$id] ?? -1) + 1).'.');
            }
        }

        return [$pinned, $cards, $problems];
    }

    /** @return ?int seconds as whole milliseconds, null when they are not a number or do not fall on a millisecond */
    private static function milliseconds(mixed $seconds): ?int
    {
        if (! is_int($seconds) && ! is_float($seconds)) {
            return null;
        }

        $ms = $seconds * 1000;

        return abs($ms - round($ms)) < 1e-6 ? (int) round($ms) : null;
    }

    /** @return array{code: string, subject: string, message: string} */
    public static function blocker(string $code, string $subject, string $message): array
    {
        return ['code' => $code, 'subject' => $subject, 'message' => $message];
    }
}
