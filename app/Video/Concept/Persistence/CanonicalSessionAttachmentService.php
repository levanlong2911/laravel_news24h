<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Models\VideoSession;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CanonicalSessionAttachmentService
{
    public function attachToSession(
        string $sessionId,
        string $revisionId
    ): void {
        DB::transaction(function () use ($sessionId, $revisionId): void {
            /** @var CanonicalConceptRevision $revision */
            $revision = CanonicalConceptRevision::query()
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($revision->status !== CanonicalConceptStatus::FROZEN) {
                throw new LogicException(
                    'Video session may reference only a frozen canonical revision.'
                );
            }

            /** @var VideoSession $session */
            $session = VideoSession::query()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->project_id !== $revision->video_project_id) {
                throw new LogicException(
                    'Canonical revision belongs to another video project.'
                );
            }

            $session->forceFill([
                'canonical_concept_revision_id' => $revision->id,
            ])->save();
        });
    }
}
