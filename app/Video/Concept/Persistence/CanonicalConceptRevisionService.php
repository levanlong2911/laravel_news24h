<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;

final class CanonicalConceptRevisionService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalEventWriter $events,
        private readonly string $canonicalSchemaVersion,
        private readonly string $conceptModel,
        private readonly string $conceptPromptVersion,
    ) {}

    /**
     * @param  string  $reason  vi sao revision nay ra doi: initial,
     *                          human_revision, vision_qa_failure,
     *                          design_change, source_update,
     *                          manual_regeneration (§10.57)
     * @param  string|null  $parentRevisionId  revision ma ban nay sinh ra
     *                          tu do (§10.56)
     */
    public function create(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $reason = 'initial',
        ?string $parentRevisionId = null,
    ): CanonicalConceptRevision {
        /*
         * Tao revision va ghi su kien dau tien phai cung mot transaction.
         *
         * Repository da co transaction rieng; long vao day thi Laravel
         * dung savepoint, nen ca hai cung commit hoac cung khong. Tach ra
         * thi mot lan crash o giua de lai revision khong co diem bat dau
         * trong so su kien — dung cho lich su tro nen kho tin.
         */
        return DB::transaction(function () use (
            $projectId,
            $sessionId,
            $input,
            $reason,
            $parentRevisionId,
        ): CanonicalConceptRevision {
            $revision = $this->repository->createRevision(
                $projectId,
                $sessionId,
                $input,
                $this->canonicalSchemaVersion,
                $this->conceptModel,
                $this->conceptPromptVersion,
                $reason,
                $parentRevisionId,
            );

            $this->events->append(
                revision: $revision,

                type: CanonicalEventType::REVISION_CREATED,

                from: null,

                to: $revision->status,

                metadata: [
                    'revision' => $revision->revision,
                    'reason' => $reason,
                    'parent_revision_id' => $parentRevisionId,
                    'profile_key' => $input->profile->key,
                    'profile_version' => $input->profile->version,
                ],

                eventKey: 'revision:created',
            );

            return $revision;
        });
    }
}
