<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Repository;

use App\Models\VideoProject;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Persistence\CanonicalConceptInputSnapshotter;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class EloquentCanonicalConceptRepository implements CanonicalConceptRepository
{
    public function __construct(
        private readonly CanonicalConceptInputSnapshotter $inputSnapshotter,
    ) {}

    public function createRevision(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $canonicalSchemaVersion,
        string $conceptModel,
        string $conceptPromptVersion,
        string $revisionReason = 'initial',
        ?string $parentRevisionId = null,
    ): CanonicalConceptRevision {
        return DB::transaction(function () use (
            $projectId,
            $sessionId,
            $input,
            $canonicalSchemaVersion,
            $conceptModel,
            $conceptPromptVersion,
            $revisionReason,
            $parentRevisionId,
        ): CanonicalConceptRevision {
            VideoProject::query()
                ->whereKey($projectId)
                ->lockForUpdate()
                ->firstOrFail();

            $revisionNumber =
                ((int) CanonicalConceptRevision::query()
                    ->where('video_project_id', $projectId)
                    ->lockForUpdate()
                    ->max('revision')) + 1;

            $snapshot = $this->inputSnapshotter->snapshot($input);

            return CanonicalConceptRevision::query()->create([
                'video_project_id' => $projectId,
                'video_session_id' => $sessionId,
                'revision' => $revisionNumber,
                'status' => CanonicalConceptStatus::PENDING,
                'object_type' => $input->objectType,
                'profile_key' => $input->profile->key,
                'profile_version' => $input->profile->version,
                'canonical_schema_version' => $canonicalSchemaVersion,
                'concept_model' => $conceptModel,
                'concept_prompt_version' => $conceptPromptVersion,
                'concept_input_json' => $snapshot['json'],
                'concept_input_hash' => $snapshot['hash'],
                'revision_reason' => $revisionReason,
                'parent_revision_id' => $parentRevisionId,
            ]);
        });
    }

    public function findRevisionForUpdate(
        string $revisionId
    ): CanonicalConceptRevision {
        $revision = CanonicalConceptRevision::query()
            ->whereKey($revisionId)
            ->lockForUpdate()
            ->first();

        if ($revision === null) {
            throw (new ModelNotFoundException)->setModel(
                CanonicalConceptRevision::class,
                [$revisionId]
            );
        }

        return $revision;
    }

    public function lockRevision(
        string $revisionId
    ): CanonicalConceptRevision {
        return $this->findRevisionForUpdate($revisionId);
    }

    public function findRevision(
        string $revisionId
    ): ?CanonicalConceptRevision {
        return CanonicalConceptRevision::query()
            ->whereKey($revisionId)
            ->first();
    }

    public function latestRevisionNumber(
        string $projectId
    ): int {
        return (int) CanonicalConceptRevision::query()
            ->where('video_project_id', $projectId)
            ->max('revision');
    }
}
