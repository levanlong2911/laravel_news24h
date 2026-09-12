<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalAttemptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalAttemptType;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Models\CanonicalConceptAttempt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use LogicException;

/**
 * Diem dung cua mot revision — doc tu status va attempts, khong can bang rieng.
 */
final class CanonicalConceptExecutionCursor
{
    public function next(CanonicalConceptRevision $revision): CanonicalConceptStatus
    {
        return match ($revision->status) {
            CanonicalConceptStatus::PENDING => CanonicalConceptStatus::GENERATING,
            CanonicalConceptStatus::GENERATED,
            CanonicalConceptStatus::REPAIRED => CanonicalConceptStatus::VALIDATING,
            CanonicalConceptStatus::VALIDATING => CanonicalConceptStatus::NORMALIZING,
            CanonicalConceptStatus::NORMALIZING => CanonicalConceptStatus::NORMALIZED,
            CanonicalConceptStatus::NORMALIZED => CanonicalConceptStatus::FREEZING,
            default => throw new LogicException(
                'Canonical revision cannot be blindly resumed from status: '
                .$revision->status->value
            ),
        };
    }

    public function successfulGeneration(
        CanonicalConceptRevision $revision
    ): ?CanonicalConceptAttempt {
        return $this->successfulAttempt(
            $revision,
            CanonicalAttemptType::GENERATION
        );
    }

    public function successfulRepair(
        CanonicalConceptRevision $revision
    ): ?CanonicalConceptAttempt {
        return $this->successfulAttempt(
            $revision,
            CanonicalAttemptType::REPAIR
        );
    }

    /**
     * Raw output moi nhat da tra tien va da luu, neu co.
     *
     * §10.66: status mot minh khong du de resume. Mot revision dung o
     * `generating` co the la "chua goi Sonnet" hoac la "da goi, da luu ket
     * qua, chet truoc khi kip chuyen trang thai". Chi attempt moi phan biet
     * duoc hai truong hop do — va chi truong hop dau moi duoc goi lai.
     *
     * Uu tien ban sua: no la trang thai tien xa nhat cua tai lieu.
     */
    public function resumableRawOutput(
        CanonicalConceptRevision $revision
    ): ?CanonicalConceptAttempt {
        return $this->successfulRepair($revision)
            ?? $this->successfulGeneration($revision);
    }

    private function successfulAttempt(
        CanonicalConceptRevision $revision,
        CanonicalAttemptType $type,
    ): ?CanonicalConceptAttempt {
        return CanonicalConceptAttempt::query()
            ->where(
                'canonical_concept_revision_id',
                $revision->id
            )
            ->where('attempt_type', $type)
            ->where('status', CanonicalAttemptStatus::SUCCEEDED)
            ->whereNotNull('raw_output')
            ->orderByDesc('attempt_number')
            ->first();
    }
}
