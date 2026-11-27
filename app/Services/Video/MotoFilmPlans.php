<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoPlanningStage;

/**
 * Where a request to cut a film and an approval read the film plan from. The plan is compiled from the approved sources
 * of the project; the two callers only ask for it, so the way it is compiled can change behind this line.
 */
interface MotoFilmPlans
{
    /**
     * @return array{ready: bool, blockers: list<array{code: string, subject: string, message: string}>, warnings: list<array<string, mixed>>, plan: ?array<string, mixed>, hash: ?string, rows: list<array<string, mixed>>, scope: array<string, mixed>, manifest: array<string, mixed>, revisions: array<string, string>, edit: ?array<string, mixed>} the plan as the sources give it now; edit is null for a project whose release cuts with no edit plan, otherwise the default edit plan for each purpose it can be cut for; reading it writes nothing
     *
     * @throws \RuntimeException when the contract is not ready or the sources cannot be read at all
     */
    public function preview(string $projectId): array;

    /**
     * @return array<string, mixed> preview(), read after everything it is read from was locked: call it inside the transaction that writes a request or an approval
     *
     * @throws \RuntimeException
     */
    public function lockedPreview(string $projectId): array;

    /**
     * Stores the edit plan a film is about to be cut from and records that the person asking confirmed it, by its hash.
     *
     * @param  array<string, mixed>  $preview  preview(), ready
     * @param  string  $purpose  what the film is cut for: the edit plan of that purpose is the one confirmed
     * @return ?array{stage_id: string, hash: string, origin: string, purpose: string, schema: string, decision_id: string, plan: array<string, mixed>} the stored plan and the decision that confirms it; null for a project whose release cuts with no edit plan
     *
     * @throws \RuntimeException when the project has no edit plan it can be cut from for that purpose
     */
    public function confirm(string $projectId, array $preview, string $purpose, string $operationId, ?string $reviewerId): ?array;

    /**
     * @param  array<string, mixed>  $preview  preview(), ready
     * @param  ?array<string, mixed>  $edit  confirm(): the edit plan the film plan was compiled for, kept with it; null for a project that cuts with none
     * @return VideoPlanningStage the saved plan with this hash, saved once however often it is sent
     *
     * @throws \RuntimeException
     */
    public function save(string $projectId, array $preview, ?array $edit = null): VideoPlanningStage;
}
