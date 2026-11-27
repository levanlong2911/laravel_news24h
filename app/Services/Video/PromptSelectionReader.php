<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Video\Superyacht\Support\CanonicalJson;
use InvalidArgumentException;

final class PromptSelectionReader
{
    public function __construct(
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly VideoReviewDecisionRepositoryInterface $decisions,
    ) {}

    /**
     * @param  PlanningStageName  $kind  KEYFRAME_PROMPT or CLIP_PROMPT: the writer whose approved prompts are read
     * @return array<string, array{stage_id: string, decision_id: string, text: string, sha256: string, version: string, fingerprint: string}> the approved prompt of every shot of this shot plan, by shot id; one whose run, decision or text no longer matches is left out, so its shot has none
     *
     * @throws InvalidArgumentException when the kind is not a prompt writer
     */
    public function selected(string $projectId, string $shotPlanId, PlanningStageName $kind): array
    {
        [$selectionKey, $entityType, $textKey] = self::keysOf($kind);
        $selection = (array) ($this->projects->findMetadata($projectId)[$selectionKey][$shotPlanId] ?? []);
        $stages = $selection === [] ? collect() : $this->stages->findSucceededStagesOfKind($projectId, $kind, array_column($selection, 'stage_id'))->keyBy('id');
        $decisions = $selection === [] ? collect() : $this->decisions->findApprovedDecisionsByIds(array_column($selection, 'decision_id'), $entityType)->keyBy('id');
        $result = [];

        foreach ($selection as $shotId => $entry) {
            $stage = $stages->get($entry['stage_id'] ?? null);
            $decision = $decisions->get($entry['decision_id'] ?? null);
            $text = (string) ($stage?->output_json['derived'][$textKey] ?? '');

            if ($stage === null || $decision === null || (string) $decision->entity_id !== (string) $stage->id || $text === ''
                || ! hash_equals((string) ($entry['prompt_sha256'] ?? ''), CanonicalJson::hashString($text))
                || (string) ($stage->input_json['shot_id'] ?? '') !== (string) $shotId) {
                continue;
            }

            $result[(string) $shotId] = [
                'stage_id' => (string) $stage->id,
                'decision_id' => (string) $decision->id,
                'text' => $text,
                'sha256' => CanonicalJson::hashString($text),
                'version' => (string) ($stage->output_json['prompt_version'] ?? $stage->instruction_version ?? ''),
                'fingerprint' => (string) $entry['source_fingerprint'],
            ];
        }

        return $result;
    }

    /**
     * @param  PlanningStageName  $kind  KEYFRAME_PROMPT or CLIP_PROMPT: the writer of the run
     * @return ?string the prompt a run wrote, word for word, while the run is a finished run of the project and the decision names it as approved; null when the run is gone, did not finish, wrote nothing or that decision is not an approval of it. Which prompt the shot uses now is not asked, so approving another one never withdraws this one
     *
     * @throws InvalidArgumentException when the kind is not a prompt writer
     */
    public function approvedRunText(string $projectId, string $runId, string $decisionId, PlanningStageName $kind): ?string
    {
        [, $entityType, $textKey] = self::keysOf($kind);
        $stage = $this->stages->findSucceededStageOfKind($projectId, $runId, $kind);
        $text = (string) ($stage?->output_json['derived'][$textKey] ?? '');
        $approved = $stage !== null && $this->decisions->isApprovalOf($decisionId, $entityType, (string) $stage->id);

        return $approved && $text !== '' ? $text : null;
    }

    /**
     * @return array<string, array<string, array{stage_id: string, decision_id: string, text: string, sha256: string, version: string, fingerprint: string, request: array<string, mixed>}>> the edit prompt approved for every candidate of this shot plan, by shot id and the rendered file it edits, with the request it was written for — the faults, what they point at, the note and the reference images kept; one whose run, decision or text no longer matches is left out
     */
    public function editSelected(string $projectId, string $shotPlanId): array
    {
        $selection = (array) ($this->projects->findMetadata($projectId)[KeyframePromptWriterService::EDIT_SELECTION][$shotPlanId] ?? []);
        $entries = [];

        foreach ($selection as $shotId => $byArtifact) {
            foreach ((array) $byArtifact as $artifactId => $entry) {
                $entries[] = [(string) $shotId, (string) $artifactId, (array) $entry];
            }
        }

        if ($entries === []) {
            return [];
        }

        $stages = $this->stages->findSucceededStagesOfKind($projectId, PlanningStageName::KEYFRAME_PROMPT, array_filter(array_map(static fn (array $row): ?string => $row[2]['stage_id'] ?? null, $entries)))->keyBy('id');
        $decisions = $this->decisions->findApprovedDecisionsByIds(array_filter(array_map(static fn (array $row): ?string => $row[2]['decision_id'] ?? null, $entries)), KeyframePromptWriterService::EDIT_ENTITY_TYPE)->keyBy('id');
        $result = [];

        foreach ($entries as [$shotId, $artifactId, $entry]) {
            $stage = $stages->get($entry['stage_id'] ?? null);
            $decision = $decisions->get($entry['decision_id'] ?? null);
            $text = (string) ($stage?->output_json['derived'][KeyframePromptWriterService::TEXT_KEY] ?? '');

            if ($stage === null || $decision === null || (string) $decision->entity_id !== (string) $stage->id || $text === ''
                || ! hash_equals((string) ($entry['prompt_sha256'] ?? ''), CanonicalJson::hashString($text))
                || (string) ($stage->input_json['shot_id'] ?? '') !== $shotId || (string) ($stage->input_json['edit_of'] ?? '') !== $artifactId) {
                continue;
            }

            $result[$shotId][$artifactId] = [
                'stage_id' => (string) $stage->id,
                'decision_id' => (string) $decision->id,
                'text' => $text,
                'sha256' => CanonicalJson::hashString($text),
                'version' => (string) ($stage->output_json['prompt_version'] ?? $stage->instruction_version ?? ''),
                'fingerprint' => (string) $entry['source_fingerprint'],
                'request' => array_intersect_key((array) ($stage->input_json['source']['edit'] ?? []), array_flip(['faults', 'targets', 'note', 'references'])),
            ];
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: string, 2: string} the project metadata key that holds the approved prompts of the writer, the entity its decisions are recorded under and the field of the derived answer that holds the prompt
     *
     * @throws InvalidArgumentException when the kind is not a prompt writer
     */
    private static function keysOf(PlanningStageName $kind): array
    {
        return match ($kind) {
            PlanningStageName::CLIP_PROMPT => [ClipPromptWriterService::SELECTION, ClipPromptWriterService::ENTITY_TYPE, ClipPromptWriterService::TEXT_KEY],
            PlanningStageName::KEYFRAME_PROMPT => [KeyframePromptWriterService::SELECTION, KeyframePromptWriterService::ENTITY_TYPE, KeyframePromptWriterService::TEXT_KEY],
            default => throw new InvalidArgumentException("Only the keyframe and clip prompt writers keep approved prompts, not {$kind->value}."),
        };
    }
}
